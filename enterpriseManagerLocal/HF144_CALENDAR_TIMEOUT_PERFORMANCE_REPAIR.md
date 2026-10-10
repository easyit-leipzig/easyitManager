# HF144 – Calendar Timeout / Performance Repair

- Standalone-Calendar releases the PHP session before DB/CSV work.
- Calendar list requests use a 30 s timeout instead of 8 s.
- CSV columns() reads only the header row instead of the entire table.
- Calendar asset cache keys raised to HF144 to prevent stale browser JS.
- Export path remains unchanged; new standalone exports receive the repaired runtime automatically.
