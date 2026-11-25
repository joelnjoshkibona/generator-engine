#!/usr/bin/env python3
"""The repository's checks, run on this machine (they replace GitHub Actions; the jobs are in tools/ci/ci.yml).

    python3 tools/ci/run.py                 every job, on HEAD, changes compared with origin/main
    python3 tools/ci/run.py api web         only these jobs
    python3 tools/ci/run.py --list          the jobs
    python3 tools/ci/run.py --ref <commit> --base <commit>

What runs is the commit (--ref, HEAD by default), never uncommitted changes: commit first. Each job gets a fresh
worktree of it beside the repository, with fresh installs, as a CI machine would; its steps run in order in their
working-directory with CI=true and CI_BASE (the commit the change checks compare with), and the job stops at its first
failing step. Jobs run one after another (the end-to-end ones use fixed ports). A failed job's worktree is kept for a
look; a passed one is removed. Logs: ~/.cache/ci-runs/<repository>/<job>.log.
"""
import argparse, os, socket, subprocess, sys, tempfile, time

try:
    import yaml
except ImportError:
    sys.exit('PyYAML is needed: pip install pyyaml (or apt install python3-yaml)')

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = subprocess.check_output(['git', 'rev-parse', '--show-toplevel'], cwd=HERE, text=True).strip()
REL = os.path.relpath(os.path.join(HERE, 'ci.yml'), ROOT)


def in_use(port):
    with socket.socket() as s:
        s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            s.bind(('127.0.0.1', port))
            return False
        except OSError:
            return True


def free_ports(ports):
    """Each declared port variable (ci.yml `ports`), moved up by 100 until it and its offsets are all free here."""
    chosen, taken = {}, set()
    for var, how in ports.items():
        port, offsets = int(how['default']), [int(o) for o in how.get('offsets', [0])]
        while any(in_use(port + o) or port + o in taken for o in offsets):
            port += 100
        if port != int(how['default']):
            print(f'   {var}={port} ({how["default"]} is in use here)', flush=True)
        taken.update(port + o for o in offsets)
        chosen[var] = str(port)
    return chosen


def main():
    p = argparse.ArgumentParser(description='Run the repository\'s checks here (tools/ci/ci.yml)')
    p.add_argument('jobs', nargs='*', help='only these jobs (default: all)')
    p.add_argument('--ref', default='HEAD', help='the commit to check (default: HEAD)')
    p.add_argument('--base', default='origin/main', help='what the change checks compare with (default: origin/main)')
    p.add_argument('--list', action='store_true', help='list the jobs and stop')
    a = p.parse_args()

    ref = subprocess.check_output(['git', 'rev-parse', '--verify', f'{a.ref}^{{commit}}'], cwd=ROOT, text=True).strip()
    found = subprocess.run(['git', 'rev-parse', '--verify', '--quiet', f'{a.base}^{{commit}}'], cwd=ROOT, capture_output=True, text=True)
    base = found.stdout.strip() or ref  # a repository not pushed yet has no origin/main: nothing to compare with
    if not found.stdout.strip():
        print(f'Note: no {a.base} here; the change checks compare the commit with itself.', flush=True)
    spec = subprocess.run(['git', 'show', f'{ref}:{REL}'], cwd=ROOT, capture_output=True, text=True)
    if spec.returncode != 0:
        sys.exit(f'{REL} is not in {a.ref} ({ref[:10]}): commit it first')
    spec = yaml.safe_load(spec.stdout)
    jobs = spec['jobs']
    if a.list:
        for key, job in jobs.items():
            print(f'{key:14} {job.get("name", key)}')
        return 0
    unknown = [j for j in a.jobs if j not in jobs]
    if unknown:
        sys.exit(f'No such job: {", ".join(unknown)} (--list names them)')
    if subprocess.run(['git', 'status', '--porcelain'], cwd=ROOT, capture_output=True, text=True).stdout.strip() and a.ref == 'HEAD':
        print('Note: uncommitted changes are not checked; this runs the last commit.', flush=True)

    logs = os.path.expanduser(f'~/.cache/ci-runs/{os.path.basename(ROOT)}')
    os.makedirs(logs, exist_ok=True)
    print(f'{os.path.basename(ROOT)} at {ref[:10]}, changes since {base[:10]}; logs in {logs}', flush=True)
    results = []
    for key, job in jobs.items():
        if a.jobs and key not in a.jobs:
            continue
        wt = tempfile.mkdtemp(prefix=f'.ci-{key}-', dir=os.path.dirname(ROOT))
        os.rmdir(wt)
        subprocess.check_call(['git', 'worktree', 'add', '-q', '--detach', wt, ref], cwd=ROOT)
        env = {**os.environ, 'CI': 'true', 'CI_BASE': base, 'HEADLESS': 'true', **free_ports(spec.get('ports') or {}),
               **{k: str(v) for k, v in (job.get('env') or {}).items()}}
        started, failed = time.time(), None
        print(f'\n== {key}: {job.get("name", key)}', flush=True)
        with open(os.path.join(logs, f'{key}.log'), 'w') as log:
            for step in job['steps']:
                label = step.get('name') or step['run'].strip().splitlines()[0]
                cwd = os.path.join(wt, step.get('working-directory', job.get('working-directory', '.')))
                log.write(f'\n######## {label}\n')
                log.flush()
                t = time.time()
                rc = subprocess.call(['bash', '-eo', 'pipefail', '-c', step['run']], cwd=cwd, stdout=log, stderr=subprocess.STDOUT,
                                     env={**env, **{k: str(v) for k, v in (step.get('env') or {}).items()}})
                print(f'   {"ok  " if rc == 0 else "FAIL"} {label} ({time.time() - t:.0f}s)', flush=True)
                if rc != 0:
                    failed = label
                    break
        if failed is None:
            subprocess.call(['git', 'worktree', 'remove', '--force', wt], cwd=ROOT)
        else:
            print(f'   the log: {logs}/{key}.log; the worktree is kept: {wt} (git worktree remove --force it after)', flush=True)
        results.append((key, failed, time.time() - started))

    print('\nSummary')
    for key, failed, secs in results:
        print(f'  {"passed" if failed is None else "FAILED"}  {key} ({secs / 60:.1f} min){f": {failed}" if failed else ""}')
    ok = all(f is None for _, f, _ in results)
    print('All green.' if ok else 'Not green.')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
