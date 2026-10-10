# HF94 – AdminAuth recursion repair

`AdminAuth::user()` logs an expired idle session out. Previously `logout()` called `user()` again, creating an infinite recursion on expired sessions until PHP exhausted its memory limit. `logout()` now reads the already stored admin identity directly from the session and never re-enters `user()`.

The repair deliberately does not increase PHP `memory_limit`; the defect was recursive control flow, not legitimate memory demand.
