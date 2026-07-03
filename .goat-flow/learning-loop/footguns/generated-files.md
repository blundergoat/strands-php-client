---
category: generated-files
last_reviewed: 2026-05-24
---

## Footgun: Python Gateway Smoke Checks Create Cache Files

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED
**hallucination-risk:** medium

**Symptoms:** After running Python gateway smoke checks or `python -m py_compile`, `git status` shows untracked `__pycache__/` directories or `.pyc` files under `examples/python-gateway/`.

**Why it happens:** The repository now contains Python example code, but generated Python bytecode caches are not part of the PHP package surface. The local smoke check in `examples/python-gateway/tests/smoke_contract.py` (search: `python gateway contract smoke OK`) imports `examples/python-gateway/contract.py` and `examples/python-gateway/tracing.py`, which can generate cache files.

**Evidence:** `examples/python-gateway/tests/smoke_contract.py` (search: `sys.path.insert`) imports local gateway modules. `examples/python-gateway/app.py` (search: `FastAPI`) is executable Python example code. Generated cache files were removed with `find examples/python-gateway -type f -name '*.pyc' -delete` after verification.

**Prevention:** After Python verification, run `git ls-files --others --exclude-standard | rg '__pycache__|\\.pyc'` and delete any generated cache files before final status. Do not include `.pyc` files in commits.
