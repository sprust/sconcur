"""A minimal keep-alive load client, to check wrk's numbers with a second tool.

Each worker thread owns one connection and issues strictly sequential requests,
so concurrency equals the thread count exactly. Throughput and latency are
measured here, which makes Little's law a self-check: throughput x mean latency
must come back to the connection count. It also counts reconnects, which is the
one thing that would let measured latency exceed what throughput allows.
"""
import socket, sys, threading, time

host, port, connections, seconds = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), float(sys.argv[4])
path = sys.argv[5] if len(sys.argv) > 5 else "/db"

request = f"GET {path} HTTP/1.1\r\nHost: {host}\r\nConnection: keep-alive\r\n\r\n".encode()
stop_at = time.perf_counter() + seconds

latencies = []
reconnects = [0]
errors = [0]
lock = threading.Lock()


def read_response(sock, buffer):
    while b"\r\n\r\n" not in buffer:
        chunk = sock.recv(65536)
        if not chunk:
            raise ConnectionError("closed")
        buffer += chunk

    head, rest = buffer.split(b"\r\n\r\n", 1)
    length = 0

    for line in head.split(b"\r\n"):
        if line.lower().startswith(b"content-length:"):
            length = int(line.split(b":", 1)[1])

    while len(rest) < length:
        chunk = sock.recv(65536)
        if not chunk:
            raise ConnectionError("closed")
        rest += chunk

    return rest[length:]


def worker():
    local = []
    sock = None
    buffer = b""

    while time.perf_counter() < stop_at:
        try:
            if sock is None:
                sock = socket.create_connection((host, port), timeout=30)
                sock.settimeout(30)
                buffer = b""

            started = time.perf_counter()
            sock.sendall(request)
            buffer = read_response(sock, buffer)
            local.append((time.perf_counter() - started) * 1000)
        except Exception:
            with lock:
                if sock is not None:
                    reconnects[0] += 1
                errors[0] += 1

            try:
                if sock:
                    sock.close()
            except Exception:
                pass

            sock = None

    if sock:
        sock.close()

    with lock:
        latencies.extend(local)


started_at = time.perf_counter()
threads = [threading.Thread(target=worker) for _ in range(connections)]

for thread in threads:
    thread.start()

for thread in threads:
    thread.join()

elapsed = time.perf_counter() - started_at
latencies.sort()
count = len(latencies)
mean = sum(latencies) / count if count else 0
rps = count / elapsed

print(
    f"conn={connections:<4} rps={rps:<10.0f} mean={mean:<8.2f}ms "
    f"p50={latencies[count//2]:<8.2f}ms p99={latencies[int(count*0.99)]:<9.2f}ms "
    f"little={rps * mean / 1000:<7.1f} reconnects={reconnects[0]} errors={errors[0]}"
)
