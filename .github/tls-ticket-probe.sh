#!/usr/bin/env bash
# Temporary diagnostic: which side stops the session tickets under OpenSSL 4.1.
set -u
OSSL4=${OSSL4:-/opt/homebrew/opt/openssl@4/bin/openssl}
OSSL3=${OSSL3:-/opt/homebrew/opt/openssl@3/bin/openssl}
cd "$RUNNER_TEMP"
"$OSSL4" version
"$OSSL3" version
php -r 'echo "PHP links ", OPENSSL_VERSION_TEXT, PHP_EOL;'
"$OSSL4" req -x509 -newkey rsa:2048 -nodes -keyout key.pem -out cert.pem -days 1 -subj /CN=localhost >/dev/null 2>&1

echo "=== A: PHP client -> s_server: extensions the PHP client offers"
for v in tls1_2 tls1_3; do
  rm -f "in-$v"
  mkfifo "in-$v"
  "$OSSL4" s_server -accept 127.0.0.1:4433 -cert cert.pem -key key.pem -tlsextdebug "-$v" < "in-$v" > "srv-$v.log" 2>&1 &
  server=$!
  exec 3>"in-$v"
  sleep 1
  php -r '$c = stream_context_create(["ssl" => ["verify_peer" => false, "verify_peer_name" => false]]);
    $s = @stream_socket_client("tls://127.0.0.1:4433", $e, $m, 5, STREAM_CLIENT_CONNECT, $c);
    echo $s ? "connected" : "failed: $m", PHP_EOL; if ($s) { usleep(300000); fclose($s); }'
  sleep 1
  exec 3>&-
  kill "$server" 2>/dev/null
  wait "$server" 2>/dev/null
  echo "--- $v client extensions:"
  grep -i "TLS client extension" "srv-$v.log" || head -20 "srv-$v.log"
done

echo "=== B: s_client -> PHP server: does the PHP server issue tickets"
for ossl in "$OSSL4" "$OSSL3"; do
  for v in tls1_2 tls1_3; do
    php -r '$c = stream_context_create(["ssl" => ["local_cert" => "cert.pem", "local_pk" => "key.pem"]]);
      $srv = stream_socket_server("tls://127.0.0.1:4434", $e, $m, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $c);
      $cl = @stream_socket_accept($srv, 10); if ($cl) { fwrite($cl, "ok"); usleep(500000); fclose($cl); }' &
    server=$!
    sleep 1
    echo "--- $("$ossl" version | cut -d" " -f2) s_client $v:"
    sleep 1 | "$ossl" s_client -connect 127.0.0.1:4434 "-$v" -tlsextdebug 2>&1 \
      | grep -i -E "TLS server extension|New Session Ticket|TLS session ticket lifetime|^ *Protocol" | sort | uniq -c
    wait "$server" 2>/dev/null
  done
done
