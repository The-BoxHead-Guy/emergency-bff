# Documentation standard

Applies to: anyone changing the files under `docs/` or the README.

The code is the single source of truth for _how_ the package works. Documentation
exists for what the code cannot state about itself:

- **What** the package provides: the HTTP contract, the responsibilities of the
  BFF versus the host, the guarantees of the emergency flow.
- **Why** a decision was taken: the constraints behind the order of operations,
  the same-origin policy, the refresh ownership limitation, and the non-breaking
  abort billing choice.
- **Process**: integration, activation, migration, upgrade and release steps.
- **Guidelines**: rules an integrator must follow to use the package correctly.

## Rules

1. Do not restate the implementation. No constructor or collaborator wiring, no
   method-level walkthroughs, no line-number references, no signatures.
2. Do not quote code that can change without a contract change: config parsing
   details, internal session keys, class internals. Name the public entry points
   (`Bootstrap::create()`, `SessionStore`) and let the code show the rest.
3. Do not quote user-facing copy. Error messages are Spanish presentation and
   can change; document `error.code` and the stable machine values instead.
4. Do not paste full request flows in bash or PHP. Keep one canonical snippet
   where an integration needs it (the front controller), and point to
   `examples/` or the packaged scripts for runnable flows.
5. One fact lives in one file. Link to it instead of repeating it. Duplicated
   paragraphs become contradictory paragraphs.
6. Do not version-stamp a page unless the behavior is version-specific. Version
   compatibility belongs in `CHANGELOG.md` and
   [Versioning](../release/versioning.md).
7. Every page starts with the reader it applies to.
8. Documentation is English. Product copy may be Spanish; describe its role, not
   its wording.

## When behavior changes

Update the page that owns the fact in the same change. If a page would only
restate the new code, delete the page instead of keeping it in sync.

## Deleting documentation

A page is safe to delete when its content is either visible in the code, already
covered by another page, or a dated report that no longer answers a question.
Prefer deleting over archiving; Git keeps the history.
