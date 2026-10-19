#!/usr/bin/env python3
"""
Takes every measurement the benchmark docs are built from, in one session.

docs/benchmarks.md, docs/load-testing.md, docs/positioning.md, the README tables
and the measured parts of docs/coroutine-switching.md and docs/http-server.md
quote numbers that have to come from one session on one machine. This runs the
existing harnesses for each of them (the bench scripts in the php container, the
HTTP load scripts on the host), repeats what the docs report as a median, and
writes what it measured to disk. It does not touch the docs: reading the results
and updating the tables is left to whoever updates them.

Usage, from the repository root on the host:

    tests/benchmarks/docs/run.py                  # every section, one after another
    tests/benchmarks/docs/run.py --db --payload   # only these sections
    tests/benchmarks/docs/run.py --list           # the sections and what they feed

    make bench-docs                               # the same through make
    make bench-docs f="--db --payload"

Output goes to .bench-runs/docs/<timestamp>/ (OUT_DIR overrides it; it must be
inside the repository so the php container sees it):

    session.json    when, on what: commit, kernel, CPU, versions, DB storage
    results.jsonl   one record per measured item: its parameters, every run,
                    and the median (min and max where the docs show them)
    raw/            the full output of every run

The terminal gets one line per item as it is measured.

Before a run: `make up`, `make ext-build`, and for the sections that measure a
database (marked "disk" in --list) the DB data on the named volumes rather than
tmpfs — see the storage note in docker-compose.yml. A disk section refuses to
run on tmpfs unless ALLOW_TMPFS=1. The DB state is reset (`make bench-reset`)
once before the first disk section unless NO_RESET=1.

Do not edit the scripts this calls while it runs: bash reads a script as it
executes it, and a run whose script changed under it dies half-way.

Tunables (env): OUT_DIR, ALLOW_TMPFS, NO_RESET, HTTP_RUNS (3, the repeats of an
HTTP measurement), DB_RUNS (5, the DB and payload repeats).
"""

import datetime
import json
import os
import platform
import re
import statistics
import subprocess
import sys
import threading
import time

REPO = os.path.realpath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
os.chdir(REPO)

EXT = '/sconcur/ext/build/sconcur.so'
HTTP = 'tests/benchmarks/http'
HTTP_RUNS = int(os.environ.get('HTTP_RUNS', '3'))
DB_RUNS = int(os.environ.get('DB_RUNS', '5'))
STAMP = datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
OUT = os.environ.get('OUT_DIR', f'.bench-runs/docs/{STAMP}')
RAW = os.path.join(OUT, 'raw')

# The servers the HTTP harnesses start inside the php container. Matched against
# a process's command line; the scanning shell's own command line contains these
# strings too, which is why only a command line starting with the binary counts.
SERVER_PATTERNS = ['php *tests/servers/http/http-server.php*', 'php *tests/servers/swoole/*', 'rr serve*']


# ---------------------------------------------------------------------------
# Plumbing


def sh(command, **kwargs):
    return subprocess.run(command, capture_output=True, text=True, **kwargs)


def say(text):
    print(text, flush=True)


def record(section, item, params, runs, summary):
    with open(os.path.join(OUT, 'results.jsonl'), 'a') as out:
        out.write(json.dumps({'section': section, 'item': item, 'params': params,
                              'runs': runs, 'summary': summary}, ensure_ascii=False) + '\n')


def save_raw(section, name, text):
    directory = os.path.join(RAW, section)
    os.makedirs(directory, exist_ok=True)
    with open(os.path.join(directory, f'{name}.txt'), 'w') as out:
        out.write(text)


def median_of(runs, key):
    values = [run[key] for run in runs if run.get(key) is not None]
    return statistics.median(values) if values else None


def php(script, args=(), env=None, extension=True):
    """Runs a bench script in the php container and returns its output."""
    command = ['docker', 'compose', 'exec', '-T']
    for key, value in (env or {}).items():
        command += ['-e', f'{key}={value}']
    command += ['php', 'php']
    if extension:
        command += ['-d', f'extension={EXT}']
    command += [script] + [str(arg) for arg in args]
    result = sh(command)
    if result.returncode != 0:
        raise RuntimeError(f'{script} failed:\n{result.stdout[-1500:]}{result.stderr[-1500:]}')
    return result.stdout


def leftover_servers():
    # A space inside a case pattern has to be escaped for sh.
    arms = ' '.join(pattern.replace(' ', '\\ ') + ') echo ${p#/proc/};;' for pattern in SERVER_PATTERNS)
    script = ('for p in /proc/[0-9]*; do c=$(tr "\\0" " " < $p/cmdline 2>/dev/null); '
              f'case "$c" in {arms} esac; done')
    return sh(['docker', 'compose', 'exec', '-T', 'php', 'sh', '-c', script]).stdout.split()


def ensure_no_servers(when):
    """A server left behind joins the next run's SO_REUSEPORT group and skews it."""
    for _ in range(30):
        if not leftover_servers():
            return
        time.sleep(1)
    pids = leftover_servers()
    say(f'  WARNING {when}: servers still running {pids}; killed')
    sh(['docker', 'compose', 'exec', '-T', 'php', 'sh', '-c', 'kill -9 ' + ' '.join(pids)])


# ---------------------------------------------------------------------------
# Parsers


def parse_benchmarker(output):
    """The native/sync/async summary every feature bench prints (lib/benchmarker.php)."""
    times = re.search(r'^Total time native/sync/async:\t(\S+)', output, re.M).group(1).split('/')
    memory = re.search(r'^Mem peak native/sync/async:\t(\S+)', output, re.M).group(1).split('/')
    return {'native_ms': float(times[0]) * 1000, 'sync_ms': float(times[1]) * 1000, 'async_ms': float(times[2]) * 1000,
            'mem_native_mb': float(memory[0]), 'mem_sync_mb': float(memory[1]), 'mem_async_mb': float(memory[2])}


def wrk_ms(value):
    value = value.strip()
    if value.endswith('us'):
        return float(value[:-2]) / 1000
    if value.endswith('ms'):
        return float(value[:-2])
    if value.endswith('s'):
        return float(value[:-1]) * 1000
    return float(value)


def parse_load(output):
    """What the HTTP load harnesses print: wrk's summary plus the resource tables."""
    def first(pattern, cast=float):
        match = re.search(pattern, output, re.M)
        return cast(match.group(1)) if match else None

    run = {
        'rps': first(r'Requests/sec:\s+([\d.]+)'),
        'p50_ms': first(r'^\s+50%\s+(\S+)', wrk_ms),
        'p90_ms': first(r'^\s+90%\s+(\S+)', wrk_ms),
        'p99_ms': first(r'^\s+99%\s+(\S+)', wrk_ms),
        'requests': first(r'^\s+(\d+) requests in', int),
        'non_2xx': first(r'Non-2xx or 3xx responses:\s+(\d+)', int) or 0,
        'socket_errors': first(r'Socket errors:\s+(.+)$', str),
        'rss_first_mib': first(r'first:\s+([\d.]+) MiB'),
        'rss_peak_mib': first(r'peak :\s+([\d.]+) MiB'),
        'rss_last_mib': first(r'last :\s+([\d.]+) MiB'),
        'rss_slope_mib_min': first(r'slope:\s+([-+\d.]+) MiB/min'),
        'spread_max_min': first(r'spread: max/min = ([\d.]+)x'),
    }
    for container in ('php', 'mongodb', 'mysql', 'postgres'):
        match = re.search(rf'^{container}\s+([\d.]+)\s+([\d.]+)\s+(\S+)', output, re.M)
        if match:
            run[f'{container}_cpu_avg'] = float(match.group(1))
            run[f'{container}_cpu_peak'] = float(match.group(2))
            run[f'{container}_mem_peak'] = match.group(3)
    if run['rps'] is None:
        raise RuntimeError('no wrk summary in the output:\n' + output[-2500:])
    return run


# ---------------------------------------------------------------------------
# HTTP runs


def truncate_bench_rw():
    """/db-rw inserts a row per request; a table grown by the previous run is slower."""
    sh(['docker', 'compose', 'exec', '-T', 'mysql', 'sh', '-c',
        'mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "TRUNCATE TABLE bench_rw"'])


def nginx_running():
    return sh(['docker', 'inspect', '-f', '{{.State.Running}}', 'sconcur-dispatch-nginx']).stdout.strip() == 'true'


def sample_nginx_cpu(stop, samples):
    while not stop.is_set():
        result = sh(['docker', 'stats', '--no-stream', '--format', '{{.CPUPerc}}', 'sconcur-dispatch-nginx'])
        if result.returncode != 0:
            return
        try:
            samples.append(float(result.stdout.strip().rstrip('%')))
        except ValueError:
            pass


def load_run(section, name, stack, env, nginx=False):
    """One run of a load harness, guarded against servers left over on either side."""
    script = {'sconcur': 'load-stats.sh', 'rr': 'rr-load-stats.sh', 'swoole': 'swoole-load-stats.sh'}[stack]
    full_env = {**os.environ, 'CONNECTIONS': '256', 'WRK_THREADS': '4', 'DURATION': '20', **env}
    if full_env.get('ROUTE') == '/db-rw':
        truncate_bench_rw()
    ensure_no_servers(f'before {name}')
    if nginx and not nginx_running():
        raise RuntimeError(f'nginx is not running before {name}')

    stop, samples, sampler = threading.Event(), [], None
    if nginx:
        sampler = threading.Thread(target=sample_nginx_cpu, args=(stop, samples))
        sampler.start()

    result = sh([f'{HTTP}/{script}'], env=full_env)

    if sampler:
        stop.set()
        sampler.join()
    output = result.stdout + result.stderr
    save_raw(section, name, output)
    if nginx and not nginx_running():
        raise RuntimeError(f'nginx died during {name}')
    ensure_no_servers(f'after {name}')

    run = parse_load(output)
    if nginx:
        run['nginx_cpu_avg'] = statistics.mean(samples[1:-1] or samples) if samples else None
    return run


def load_summary(runs):
    keys = ['rps', 'p50_ms', 'p90_ms', 'p99_ms', 'php_cpu_avg', 'php_cpu_peak', 'mysql_cpu_avg', 'mongodb_cpu_avg',
            'postgres_cpu_avg', 'nginx_cpu_avg', 'rss_first_mib', 'rss_last_mib', 'spread_max_min']
    summary = {key: median_of(runs, key) for key in keys if any(run.get(key) is not None for run in runs)}
    summary['non_2xx_max'] = max(run['non_2xx'] for run in runs)
    memories = sorted(run.get('php_mem_peak') or '-' for run in runs)
    summary['php_mem_peak_median'] = memories[len(memories) // 2]
    return summary


def show_load(section, item, summary, runs):
    def ms(value):
        return '-' if value is None else (f'{value / 1000:.2f} s' if value >= 1000 else f'{value:.2f} ms')
    rps = f'{summary["rps"]:,.0f}'.replace(',', ' ')
    line = (f'[{section}] {item}: {rps} rps, p50 {ms(summary.get("p50_ms"))}, '
            f'p90 {ms(summary.get("p90_ms"))}, p99 {ms(summary.get("p99_ms"))}, '
            f'php CPU ~{summary.get("php_cpu_avg", 0):.0f}%')
    if summary.get('mysql_cpu_avg') is not None:
        line += f', mysql ~{summary["mysql_cpu_avg"]:.0f}%'
    if summary.get('nginx_cpu_avg') is not None:
        line += f', nginx ~{summary["nginx_cpu_avg"]:.0f}%'
    if summary.get('spread_max_min') is not None:
        line += f', spread {summary["spread_max_min"]:.2f}x'
    line += f' (median of {len(runs)})'
    if summary['non_2xx_max']:
        line += f'  !! non-2xx {summary["non_2xx_max"]}'
    say(line)


def measure_interleaved(section, arms, runs=HTTP_RUNS):
    """Each arm `runs` times, rounds interleaved so drift lands on every arm alike.

    arms: list of (item, stack, env, nginx_strategy_or_None).
    """
    collected = {item: [] for item, *_ in arms}
    for round_index in range(runs):
        for item, stack, env, strategy in arms:
            name = f'{item}-r{round_index}'.replace('/', '_').replace(' ', '_')
            if strategy:
                nginx_proxy('start', strategy, env.get('SERVERS', '8'))
            try:
                collected[item].append(load_run(section, name, stack, env, nginx=bool(strategy)))
            finally:
                if strategy:
                    nginx_proxy('stop')
    for item, stack, env, strategy in arms:
        summary = load_summary(collected[item])
        record(section, item, {'stack': stack, 'env': env, 'nginx': strategy}, collected[item], summary)
        show_load(section, item, summary, collected[item])


def nginx_proxy(command, strategy=None, servers='8'):
    env = {**os.environ, 'SERVERS': str(servers), 'CPUSET': '8-11'}
    if strategy:
        env['STRATEGY'] = strategy
    result = sh([f'{HTTP}/nginx-proxy.sh', command], env=env)
    if result.returncode != 0:
        raise RuntimeError(f'nginx {command} failed: {result.stdout}{result.stderr}')


def stack_env(stack, workers, extra=None):
    """The worker count and placement each harness takes. SConcur runs `group`, the
    placement the RoadRunner and Swoole harnesses get from taskset on the master."""
    env = {'SERVERS': str(workers), 'PIN_SERVERS': 'group'} if stack == 'sconcur' else {'WORKERS': str(workers)}
    env.update(extra or {})
    return env


# ---------------------------------------------------------------------------
# Feature sections (docs/benchmarks.md)


def repeated_feature(section, item, script, args, env, runs, extension=True):
    collected = []
    for index in range(runs):
        output = php(script, args, env, extension)
        save_raw(section, f'{item}-r{index}'.replace(' ', '_').replace('/', '_'), output)
        collected.append(parse_benchmarker(output))
    summary = {}
    for key in collected[0]:
        values = [run[key] for run in collected]
        summary[key] = statistics.median(values)
        if key.endswith('_ms'):
            summary[key.replace('_ms', '_min_ms')] = min(values)
            summary[key.replace('_ms', '_max_ms')] = max(values)
    record(section, item, {'script': script, 'args': list(args), 'env': env}, collected, summary)
    say(f'[{section}] {item}: native {summary["native_ms"]:.2f} / sync {summary["sync_ms"]:.2f} / '
        f'async {summary["async_ms"]:.2f} ms, memory {summary["mem_native_mb"]:.0f} / {summary["mem_sync_mb"]:.0f} / '
        f'{summary["mem_async_mb"]:.0f} MB (median of {runs})')


def section_db():
    """MongoDB / MySQL / PostgreSQL tables: db/runs.sh, DB_RUNS cold runs per bench."""
    directory = os.path.join(OUT, 'db')
    result = sh(['tests/benchmarks/db/runs.sh'], env={**os.environ, 'RUNS': str(DB_RUNS), 'OUT_DIR': directory})
    save_raw('db', 'runs-sh', result.stdout + result.stderr)
    if result.returncode != 0:
        raise RuntimeError('db/runs.sh failed:\n' + (result.stdout + result.stderr)[-2500:])
    for name in sorted(os.listdir(directory)):
        if not name.endswith('.csv'):
            continue
        runs = []
        for line in open(os.path.join(directory, name)).read().split('\n'):
            if not line.strip():
                continue
            calls, times, memory = line.split(';')
            native, sync, async_ = (float(value) * 1000 for value in times.split('/'))
            mem = [float(value) for value in memory.split('/')]
            runs.append({'calls': int(calls), 'native_ms': native, 'sync_ms': sync, 'async_ms': async_,
                         'mem_native_mb': mem[0], 'mem_sync_mb': mem[1], 'mem_async_mb': mem[2]})
        summary = {key: statistics.median(run[key] for run in runs) for key in runs[0] if key != 'calls'}
        for key in ('native_ms', 'sync_ms', 'async_ms'):
            summary[key.replace('_ms', '_min_ms')] = min(run[key] for run in runs)
            summary[key.replace('_ms', '_max_ms')] = max(run[key] for run in runs)
        item = name[:-4]
        record('db', item, {'calls': runs[0]['calls']}, runs, summary)
        say(f'[db] {item} x{runs[0]["calls"]}: native {summary["native_ms"]:.2f} / sync {summary["sync_ms"]:.2f} / '
            f'async {summary["async_ms"]:.2f} ms (median of {len(runs)})')


def section_payload():
    """Payload size tables: 1 KB / 64 KB / 1 MB per call, six benches each."""
    for size, calls in ((1024, 100), (65536, 100), (1048576, 50)):
        for feature, operation in (('mongodb', 'payload-write'), ('mongodb', 'payload-read'), ('mysql', 'payload-write'),
                                   ('mysql', 'payload-read'), ('pgsql', 'payload-write'), ('pgsql', 'payload-read')):
            repeated_feature('payload', f'{feature} {operation} {size} B x{calls}',
                             f'tests/benchmarks/{feature}/{operation}.php', [calls],
                             {'SCONCUR_BENCH_PAYLOAD_BYTES': size}, DB_RUNS)


def section_amqp():
    """AMQP: publish and get per message, and the rate of consuming a full queue."""
    for name in ('publish', 'get'):
        repeated_feature('amqp', f'{name} x1000', f'tests/benchmarks/amqp/{name}.php', [1000], {}, DB_RUNS)
    runs = []
    for index in range(DB_RUNS):
        output = php('tests/benchmarks/amqp/consume.php', [10, 200])
        save_raw('amqp', f'consume-r{index}', output)
        run = {}
        for mode in ('native', 'sconcur sync', 'sconcur async'):
            match = re.search(rf'^\s+{mode}\b.*?([\d.]+) s\s+(\d+) msg/s', output, re.M)
            run[mode.replace(' ', '_') + '_msg_s'] = float(match.group(2))
        runs.append(run)
    summary = {key: statistics.median(run[key] for run in runs) for key in runs[0]}
    record('amqp', 'consume', {'args': [10, 200]}, runs, summary)
    say(f'[amqp] consume: native {summary["native_msg_s"]:.0f} / sync {summary["sconcur_sync_msg_s"]:.0f} / '
        f'async {summary["sconcur_async_msg_s"]:.0f} msg/s (median of {len(runs)})')


def section_redis():
    """Redis: get / set / pipeline, and the value-size table."""
    for name, calls in (('get', 2000), ('set', 2000), ('pipeline', 200)):
        repeated_feature('redis', f'{name} x{calls}', f'tests/benchmarks/redis/{name}.php', [calls], {}, DB_RUNS)
    for size in (100, 10240, 1048576):
        repeated_feature('redis', f'value-size {size} B x500', 'tests/benchmarks/redis/value-size.php', [500, 0, size],
                         {}, 1)


def section_files():
    """Files: read / write / copy / hashFile / readChunks per size, and the listing."""
    sizes = {'1 KiB': (1024, 20), '1 MiB': (1048576, 30), '10 MiB': (10485760, 30), '64 MiB': (67108864, 6)}
    for operation, script, labels in (('read', 'read', ('1 KiB', '1 MiB', '64 MiB')),
                                      ('write', 'write', ('1 KiB', '1 MiB', '64 MiB')),
                                      ('copy', 'copy', ('1 KiB', '1 MiB', '64 MiB')),
                                      ('hashFile', 'hash-file', ('10 MiB', '64 MiB')),
                                      ('readChunks', 'read-stream', ('10 MiB', '64 MiB'))):
        for label in labels:
            size, calls = sizes[label]
            repeated_feature('files', f'{operation} {label} x{calls}', f'tests/benchmarks/files/{script}.php',
                             [calls, 0], {'SCONCUR_BENCH_FILE_BYTES': size}, 1)
    for entries, calls in ((10000, 30), (100000, 5)):
        repeated_feature('files', f'list {entries} entries x{calls}', 'tests/benchmarks/files/list-dir.php',
                         [calls, 0], {'SCONCUR_BENCH_DIR_ENTRIES': entries}, 1)


def section_clients():
    """Clients table: 50 round-trips to the demo servers' msleep endpoints."""
    for script in ('http/client', 'socket/client', 'ws/client'):
        repeated_feature('clients', f'{script} x50', f'tests/benchmarks/{script}.php', [50], {}, HTTP_RUNS)


def section_servers():
    """Servers table: the pool's elapsed time, and round-trips per second for throughput."""
    for script in ('http/server-io', 'http/server-cpu', 'socket/server-io', 'socket/server-cpu', 'socket/throughput',
                   'ws/server-io', 'ws/server-cpu', 'ws/throughput'):
        runs = []
        for index in range(HTTP_RUNS):
            output = php(f'tests/benchmarks/{script}.php', extension=False)
            save_raw('servers', f'{script.replace("/", "-")}-r{index}', output)
            elapsed = re.search(r'servers pool:\s+([\d.]+) s', output)
            rate = re.search(r'([\d.]+) rt/s', output)
            runs.append({'elapsed_s': float(elapsed.group(1)) if elapsed else None,
                         'rt_s': float(rate.group(1)) if rate else None})
        summary = {key: median_of(runs, key) for key in ('elapsed_s', 'rt_s')}
        record('servers', script, {}, runs, summary)
        rate = f', {summary["rt_s"]:.0f} rt/s' if summary['rt_s'] is not None else ''
        say(f'[servers] {script}: {summary["elapsed_s"]:.3f} s{rate} (median of {len(runs)})')


# ---------------------------------------------------------------------------
# HTTP stack sections (docs/benchmarks.md, README, docs/positioning.md)


STACKS = ('rr', 'sconcur', 'swoole')
LADDER = (1, 3, 8, 16)
# The /db* pool per process: one connection per concurrent request a worker can
# have, divided so the whole ladder stays under MySQL's max_connections.
DB_POOLS = {1: '150', 3: '50', 8: '18', 16: '9'}


def section_http_compare():
    """The three stacks on the empty endpoint at 12 workers, plus Swoole with a
    stronger generator: the check that its row is the server's ceiling."""
    measure_interleaved('http-compare', [(f'{stack} / x12', stack, stack_env(stack, 12, {'ROUTE': '/'}), None)
                                         for stack in STACKS])
    measure_interleaved('http-compare', [('swoole / x12 wrk8', 'swoole',
                                          stack_env('swoole', 12, {'ROUTE': '/', 'WRK_THREADS': '8'}), None)], runs=1)


def ladder(section, route, pools):
    for workers in LADDER:
        arms = []
        for stack in STACKS:
            extra = {'ROUTE': route}
            if pools and stack != 'rr':
                extra['DB_POOL_SIZE'] = pools[workers]
            arms.append((f'{stack} {route} x{workers}', stack, stack_env(stack, workers, extra), None))
        measure_interleaved(section, arms)


def section_http_ladder_empty():
    ladder('http-ladder-empty', '/', None)


def section_http_ladder_db():
    ladder('http-ladder-db', '/db', DB_POOLS)


def section_http_ladder_db_rw():
    ladder('http-ladder-db-rw', '/db-rw', DB_POOLS)


def section_http_all():
    """/all at 12 workers: SConcur against RoadRunner on its native drivers."""
    measure_interleaved('http-all', [(f'{stack} /all x12', stack, stack_env(stack, 12, {'ROUTE': '/all'}), None)
                                     for stack in ('sconcur', 'rr')])


# ---------------------------------------------------------------------------
# docs/load-testing.md


def section_load_testing():
    """Baseline / reference runs, /all against /all-nowg, single connection, pinning."""
    measure_interleaved('load-testing', [
        ('sconcur / x12', 'sconcur', stack_env('sconcur', 12, {'ROUTE': '/'}), None),
        ('sconcur /all x12', 'sconcur', stack_env('sconcur', 12, {'ROUTE': '/all'}), None),
        ('sconcur /all-nowg x12', 'sconcur', stack_env('sconcur', 12, {'ROUTE': '/all-nowg'}), None),
    ])
    single = {'WRK_THREADS': '1', 'CONNECTIONS': '1', 'DURATION': '5'}
    measure_interleaved('load-testing', [
        ('sconcur /all x1 single connection', 'sconcur', stack_env('sconcur', 1, {'ROUTE': '/all', **single}), None),
        ('sconcur /all-nowg x1 single connection', 'sconcur',
         stack_env('sconcur', 1, {'ROUTE': '/all-nowg', **single}), None),
    ])
    measure_interleaved('load-testing', [
        (f'pin {mode} / x12', 'sconcur', {'SERVERS': '12', 'PIN_SERVERS': mode, 'ROUTE': '/'}, None)
        for mode in ('1', 'group', 'physical')
    ])


def section_soak():
    """The ten-minute soak on /all: RSS trend and slope."""
    run = load_run('soak', 'soak-all', 'sconcur', {'SERVERS': '12', 'PIN_SERVERS': 'group', 'ROUTE': '/all',
                                                   'MODE': 'soak'})
    summary = load_summary([run])
    record('soak', 'sconcur /all soak', {}, [run], summary)
    show_load('soak', 'sconcur /all soak', summary, [run])


# ---------------------------------------------------------------------------
# docs/coroutine-switching.md, docs/http-server.md


MIXED = {'WRK_SCRIPT': f'{HTTP}/mixed-profile.lua', 'HEAVY_PATH': '/cpu/300000', 'HEAVY_RATIO': '0.10', 'ROUTE': '/',
         # wrk drops a request past its timeout from the distribution; the tail here reaches seconds.
         'WRK_TIMEOUT': '10s', 'SERVERS': '8', 'PIN_SERVERS': 'group', 'WRK_CPULIST': '12-15'}


def section_preemption():
    """The cost of preemption under load: the quantum, then maxConcurrency at 5 ms.
    8 workers, 10% of requests on a CPU-bound handler (/cpu/300000)."""
    arms = [(f'quantum {q} maxConcurrency {c}', 'sconcur',
             {**MIXED, 'SERVER_ARGS': f'--preemptionQuantumMs={q}', 'MAXCONCURRENCY': str(c)}, None)
            for q, c in ((5, 0), (20, 0), (50, 0), (100, 0), (0, 0), (5, 16), (5, 8), (5, 4))]
    measure_interleaved('preemption', arms)


def section_proxy():
    """A proxy in front of the pool: SO_REUSEPORT against nginx's three strategies,
    empty endpoint, 8 workers on 0-7, nginx on 8-11, wrk on 12-15."""
    base = {'SERVERS': '8', 'PIN_SERVERS': 'group', 'WRK_CPULIST': '12-15', 'ROUTE': '/'}
    measure_interleaved('proxy', [
        ('SO_REUSEPORT', 'sconcur', base, None),
        ('nginx round-robin', 'sconcur', {**base, 'DISTINCT_PORTS': '1'}, 'round_robin'),
        ('nginx least_conn', 'sconcur', {**base, 'DISTINCT_PORTS': '1'}, 'least_conn'),
        ('nginx random two least_conn', 'sconcur', {**base, 'DISTINCT_PORTS': '1'}, 'random2'),
    ])


def section_spread():
    """How evenly SO_REUSEPORT spreads requests over 8 workers (per-worker access logs)."""
    base = {'SERVERS': '8', 'PIN_SERVERS': 'group', 'WRK_CPULIST': '12-15', 'ROUTE': '/', 'WORKER_LOGS': '1'}
    measure_interleaved('spread', [(f'{connections} connections', 'sconcur', {**base, 'CONNECTIONS': connections}, None)
                                   for connections in ('256', '32')])


# ---------------------------------------------------------------------------


SECTIONS = {
    # name: (function, needs the DB on disk, what it feeds)
    'db': (section_db, True, 'benchmarks: MongoDB, MySQL, PostgreSQL tables'),
    'payload': (section_payload, True, 'benchmarks: payload size tables'),
    'amqp': (section_amqp, False, 'benchmarks: AMQP tables'),
    'redis': (section_redis, False, 'benchmarks: Redis tables'),
    'files': (section_files, False, 'benchmarks: Files tables, docs/files.md verdicts'),
    'clients': (section_clients, False, 'benchmarks: clients table'),
    'servers': (section_servers, False, 'benchmarks: servers table'),
    'http-compare': (section_http_compare, False, 'benchmarks: comparison with RoadRunner and Swoole, README, positioning'),
    'http-ladder-empty': (section_http_ladder_empty, False, 'benchmarks: empty-endpoint worker ladder'),
    'http-ladder-db': (section_http_ladder_db, True, 'benchmarks: /db ladder, README'),
    'http-ladder-db-rw': (section_http_ladder_db_rw, True, 'benchmarks: /db-rw ladder, README'),
    'http-all': (section_http_all, True, 'positioning: /all rows'),
    'load-testing': (section_load_testing, True, 'load-testing: baseline, reference, /all vs /all-nowg, pinning'),
    'soak': (section_soak, True, 'load-testing: soak run (10 minutes)'),
    'preemption': (section_preemption, False, 'coroutine-switching: cost under load; http-server: maxConcurrency'),
    'proxy': (section_proxy, False, 'http-server: a proxy in front of the pool'),
    'spread': (section_spread, False, 'http-server: how evenly the kernel spreads'),
}


def storage():
    """'volume' when the MySQL data sits on a named volume, 'tmpfs' when on tmpfs."""
    mounts = sh(['docker', 'inspect', '-f', '{{json .Mounts}}', 'sc-mysql']).stdout
    if '"Type":"volume"' in mounts:
        return 'volume'
    tmpfs = sh(['docker', 'inspect', '-f', '{{json .HostConfig.Tmpfs}}', 'sc-mysql']).stdout
    return 'tmpfs' if 'mysql' in tmpfs else 'unknown'


def session_info(sections, db_storage):
    def out(command):
        return sh(command).stdout.strip()
    cpu = next((line.split(':', 1)[1].strip() for line in open('/proc/cpuinfo') if line.startswith('model name')), '')
    return {
        'started_at': datetime.datetime.now().isoformat(timespec='seconds'),
        'sections': sections,
        'git_commit': out(['git', 'rev-parse', 'HEAD']),
        'git_branch': out(['git', 'rev-parse', '--abbrev-ref', 'HEAD']),
        'git_dirty': bool(out(['git', 'status', '--porcelain'])),
        'kernel': platform.release(),
        'os': out(['sh', '-c', '. /etc/os-release && echo "$PRETTY_NAME"']),
        'cpu': cpu,
        'cpus': os.cpu_count(),
        'docker': out(['docker', 'version', '--format', '{{.Server.Version}}']),
        'php': out(['docker', 'compose', 'exec', '-T', 'php', 'php', '-r', 'echo PHP_VERSION;']),
        'extension': out(['docker', 'compose', 'exec', '-T', 'php', 'php', '-d', f'extension={EXT}', '-r',
                          'echo SConcur\\Extension\\version();']),
        'db_storage': db_storage,
        'http_runs': HTTP_RUNS,
        'db_runs': DB_RUNS,
    }


def main(argv):
    if '--list' in argv or '--help' in argv or '-h' in argv:
        say('sections (--<name> runs it; no flags run all of them in this order):')
        for name, (_, disk, feeds) in SECTIONS.items():
            say(f'  --{name:<18} {"disk " if disk else "     "} {feeds}')
        return 0

    # Sections are picked by flag (--db) or by bare name (db); none means all, in order.
    picked = [arg[2:] if arg.startswith('--') else arg for arg in argv]
    unknown = [name for name in picked if name not in SECTIONS]
    if unknown:
        say(f'unknown section(s): {", ".join(unknown)}; see --list')
        return 2
    sections = [name for name in SECTIONS if name in picked] if picked else list(SECTIONS)

    if not sh(['docker', 'compose', 'ps', '-q', 'php']).stdout.strip():
        say('the php container is not running (make up)')
        return 1
    if not os.path.exists('ext/build/sconcur.so'):
        say('ext/build/sconcur.so is missing (make ext-build)')
        return 1

    needs_disk = any(SECTIONS[name][1] for name in sections)
    db_storage = storage()
    if needs_disk and db_storage != 'volume' and os.environ.get('ALLOW_TMPFS') != '1':
        say(f'the DB data is on {db_storage}, not on the named volumes; the sections '
            f'{", ".join(n for n in sections if SECTIONS[n][1])} measure disk-backed backends '
            '(see docker-compose.yml, or ALLOW_TMPFS=1 to run anyway)')
        return 1

    os.makedirs(RAW, exist_ok=True)
    with open(os.path.join(OUT, 'session.json'), 'w') as out:
        json.dump(session_info(sections, db_storage), out, indent=2, ensure_ascii=False)
    say(f'results: {OUT}')

    ensure_no_servers('before the session')
    if needs_disk and os.environ.get('NO_RESET') != '1':
        say('resetting the DB state (make bench-reset)')
        sh(['make', 'bench-reset'])

    failed = []
    for name in sections:
        started = time.time()
        say(f'== {name}')
        try:
            SECTIONS[name][0]()
        except Exception as error:  # one broken section must not cost the rest of a long session
            failed.append(name)
            say(f'!! {name} failed: {error}')
        say(f'== {name} done in {time.time() - started:.0f} s')

    if failed:
        say(f'failed sections: {", ".join(failed)}')
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
