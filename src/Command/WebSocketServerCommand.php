<?php

namespace App\Command;

use App\Service\LedStateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:websocket-server', description: 'WebSocket server for live LED state updates')]
class WebSocketServerCommand extends Command
{
    public function __construct(private readonly LedStateService $ledState)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Port to listen on', 8001);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = (int) $input->getOption('port');
        $stateFile = $this->ledState->getStateFilePath();

        $server = stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);
        if (!$server) {
            $output->writeln("<error>Failed to bind port {$port}: {$errstr}</error>");
            return Command::FAILURE;
        }
        stream_set_blocking($server, false);

        $output->writeln("WebSocket server listening on ws://0.0.0.0:{$port}");

        $clients = [];       // id => socket resource
        $clientState = [];   // id => 'handshake'|'connected'
        $lastHash = '';

        while (true) {
            $read = array_values($clients);
            $read[] = $server;
            $write = $except = null;

            // stream_select returns false on signal (SIGINT), which exits the loop
            if (stream_select($read, $write, $except, 0, 40000) === false) {
                break;
            }

            foreach ($read as $sock) {
                if ($sock === $server) {
                    $conn = @stream_socket_accept($server, 0);
                    if ($conn) {
                        stream_set_blocking($conn, false);
                        $clients[(int) $conn] = $conn;
                        $clientState[(int) $conn] = 'handshake';
                    }
                    continue;
                }

                $id = (int) $sock;
                $data = fread($sock, 8192);

                if ($data === false || ($data === '' && feof($sock))) {
                    fclose($sock);
                    unset($clients[$id], $clientState[$id]);
                    continue;
                }

                if ($data === '') {
                    continue; // non-blocking read with no data yet
                }

                if (($clientState[$id] ?? '') === 'handshake') {
                    fwrite($sock, $this->buildHandshakeResponse($data));
                    $clientState[$id] = 'connected';
                    fwrite($sock, $this->encodeFrame(json_encode($this->ledState->getSiPayload())));
                } elseif (($clientState[$id] ?? '') === 'connected') {
                    $payload = $this->decodeFrame($data);
                    if ($payload !== null && $payload !== '') {
                        $update = json_decode($payload, true);
                        if (!is_array($update)) {
                            // ignore unparseable frames
                        } elseif (!empty($update['v'])) {
                            // {"v":true} — explicit state request
                            fwrite($sock, $this->encodeFrame(json_encode($this->ledState->getSiPayload())));
                        } elseif (!empty($update)) {
                            $this->ledState->applyUpdate($update);
                        }
                    }
                }
            }

            if (file_exists($stateFile)) {
                $hash = md5_file($stateFile);
                if ($hash !== $lastHash) {
                    $lastHash = $hash;
                    $frame = $this->encodeFrame(json_encode($this->ledState->getSiPayload()));
                    foreach ($clients as $id => $sock) {
                        if (($clientState[$id] ?? '') !== 'connected') {
                            continue;
                        }
                        // Only write when the socket can accept data; skip rather than
                        // close when the buffer is full (e.g. a sender that doesn't read).
                        $w = [$sock];
                        $n = null;
                        if (@stream_select($n, $w, $n, 0, 0) > 0) {
                            if (@fwrite($sock, $frame) === false) {
                                @fclose($sock);
                                unset($clients[$id], $clientState[$id]);
                            }
                        }
                    }
                }
            }
        }

        fclose($server);
        return Command::SUCCESS;
    }

    private function decodeFrame(string $data): ?string
    {
        if (strlen($data) < 2) {
            return null;
        }

        $byte1 = ord($data[0]);
        $byte2 = ord($data[1]);
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $len = $byte2 & 0x7F;
        $offset = 2;

        // Only handle text frames (opcode 1)
        if ($opcode !== 1) {
            return null;
        }

        if ($len === 126) {
            if (strlen($data) < 4) {
                return null;
            }
            $len = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($len === 127) {
            if (strlen($data) < 10) {
                return null;
            }
            $len = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }

        if ($masked) {
            if (strlen($data) < $offset + 4 + $len) {
                return null;
            }
            $mask = substr($data, $offset, 4);
            $offset += 4;
            $payload = substr($data, $offset, $len);
            $unmasked = '';
            for ($i = 0; $i < $len; $i++) {
                $unmasked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
            }
            return $unmasked;
        }

        if (strlen($data) < $offset + $len) {
            return null;
        }

        return substr($data, $offset, $len);
    }

    private function buildHandshakeResponse(string $request): string
    {
        if (!preg_match('/Sec-WebSocket-Key:\s*(.+)/i', $request, $m)) {
            return '';
        }
        $accept = base64_encode(sha1(trim($m[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        return "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n";
    }

    private function encodeFrame(string $data): string
    {
        $len = strlen($data);
        if ($len < 126) {
            return "\x81" . chr($len) . $data;
        }
        if ($len < 65536) {
            return "\x81\x7e" . pack('n', $len) . $data;
        }
        return "\x81\x7f" . pack('J', $len) . $data;
    }
}