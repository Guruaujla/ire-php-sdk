<?php

declare(strict_types=1);

/*
 * Raw TCP capture listener for TransportWireCompatTest.
 *
 * NOT a test file — PHPUnit only collects `*Test.php`, so this is never
 * executed as one. It is `proc_open`ed as a CHILD PROCESS because PHP is
 * single-threaded: a listener in the test process itself would have to accept
 * the connection the test process is simultaneously blocked on writing.
 *
 * Why a raw socket instead of a mock PSR-18 client: the defect this pins
 * (issue #194) lives BELOW PSR-7 — ext-curl re-serializes the URL it is handed
 * and upper-cases percent-escape hex in the path. Nothing that stops short of
 * the wire can see it, which is exactly why a green unit suite and a green
 * wire-fixture suite both missed it.
 *
 *   argv[1]  number of connections to serve before exiting
 *   argv[2]  output path; one JSON object per request, one per line:
 *            {"method","target","headers":{lowercased},"body"}
 *
 * Prints "PORT <n>\n" on stdout the moment it is bound. The port is chosen by
 * the KERNEL (bind to :0) and reported back rather than fixed, so a listener
 * left behind by another suite — or by a parallel checkout — can never be the
 * thing the test talks to. Every socket carries a timeout: a wedged peer must
 * fail the test, not hang CI.
 */

$count = (int) ($argv[1] ?? '1');
$out = (string) ($argv[2] ?? '');
if ($count < 1 || $out === '') {
    fwrite(STDERR, "usage: wire-capture-listener.php <connections> <output-path>\n");
    exit(2);
}

/** Seconds to wait for a connection that the test says is coming. */
const ACCEPT_TIMEOUT_SECONDS = 10;

/** Seconds to wait for the bytes of a request already being received. */
const READ_TIMEOUT_SECONDS = 5;

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr} ({$errno})\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
$colon = strrpos($name, ':');
echo 'PORT ' . ($colon === false ? '0' : substr($name, $colon + 1)) . "\n";
flush();

$handle = fopen($out, 'wb');
if ($handle === false) {
    fwrite(STDERR, "cannot open {$out} for writing\n");
    exit(1);
}

for ($i = 0; $i < $count; $i++) {
    $conn = @stream_socket_accept($server, ACCEPT_TIMEOUT_SECONDS);
    if ($conn === false) {
        break;
    }
    stream_set_timeout($conn, READ_TIMEOUT_SECONDS);

    // Read up to and including the header terminator. Read in chunks rather
    // than fgets() per line: the request line is the artefact under test and
    // must be captured as raw bytes, with no newline handling in between.
    $raw = '';
    while (! str_contains($raw, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    /** @var array{0: string, 1: string} $split */
    $split = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    [$head, $rest] = $split;

    $lines = explode("\r\n", $head);
    $requestLine = (string) array_shift($lines);
    /** @var array<string, string> $headers */
    $headers = [];
    foreach ($lines as $line) {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $headers[strtolower(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
    }

    // Drain the body before answering. Replying early would let curl see the
    // response mid-upload and report a transport error instead of the clean
    // exchange the assertions read.
    $length = isset($headers['content-length']) ? (int) $headers['content-length'] : 0;
    $body = $rest;
    while (true) {
        $remaining = $length - strlen($body);
        if ($remaining < 1) {
            break;
        }
        $chunk = fread($conn, $remaining);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    // explode() always yields at least one element, so [0] is the method even
    // for a malformed request line; only the target may genuinely be absent.
    $parts = explode(' ', $requestLine);
    fwrite($handle, json_encode(
        [
            'method' => $parts[0],
            'target' => $parts[1] ?? '',
            'headers' => $headers,
            'body' => $body,
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n");
    fflush($handle);

    // `Connection: close` keeps every exchange on its own connection, so a
    // capture can never be attributed to the wrong request.
    fwrite(
        $conn,
        "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}",
    );
    fclose($conn);
}

fclose($handle);
fclose($server);
