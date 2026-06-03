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
        $lastMtime = 0;
        $lastCheck = 0.0;

        while (true) {
            $read = array_values($clients);
            $read[] = $server;
            $write = $except = null;

            // stream_select returns false on signal (SIGINT), which exits the loop
            if (stream_select($read, $write, $except, 0, 50000) === false) {
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

                if ($data === false || $data === '') {
                    fclose($sock);
                    unset($clients[$id], $clientState[$id]);
                    continue;
                }

                if (($clientState[$id] ?? '') === 'handshake') {
                    fwrite($sock, $this->buildHandshakeResponse($data));
                    $clientState[$id] = 'connected';
                    fwrite($sock, $this->encodeFrame(json_encode($this->ledState->getState())));
                }
            }

            $now = microtime(true);
            if ($now - $lastCheck >= 0.05) {
                $lastCheck = $now;
                clearstatcache(true, $stateFile);
                if (file_exists($stateFile)) {
                    $mtime = filemtime($stateFile);
                    if ($mtime !== $lastMtime) {
                        $lastMtime = $mtime;
                        $frame = $this->encodeFrame(json_encode($this->ledState->getState()));
                        foreach ($clients as $id => $sock) {
                            if (($clientState[$id] ?? '') === 'connected') {
                                if (@fwrite($sock, $frame) === false) {
                                    @fclose($sock);
                                    unset($clients[$id], $clientState[$id]);
                                }
                            }
                        }
                    }
                }
            }
        }

        fclose($server);
        return Command::SUCCESS;
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