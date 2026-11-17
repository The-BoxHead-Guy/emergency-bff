# AGENTS.md — emergency-bff

Package-level rules for agents working in `packages/emergency-bff`. They override
the monorepo defaults where they conflict.

## Comments and PHPDoc

- State **what** the method or block does in one short sentence.
- Never explain why it exists, its history, or the reasoning behind a decision.
- Do not restate the method signature, narrate obvious code, or leave
  commented-out code behind.
- If the code needs a "why" comment to be understood, make the code clearer
  instead.

## Tests

- Assert behavior and outcomes, never user-facing copy, labels, icons or layout.
  Pinning flexible presentation makes it brittle and does not protect the
  contract.
- Cover the failure paths the change adds, including cleanup side effects.

## Documentation

- The code is the single source of truth. Documentation records business-logic
  decisions and the public HTTP contract, not what the code already shows.
- Do not document constructors, collaborator wiring, signatures, parsing
  mechanics, or change history. The code and `CHANGELOG.md` cover those.
- If a doc only restates the implementation and can go stale after a refactor,
  delete it instead of maintaining it.

## Upstream contracts

Do not call an in-use contract "legacy". Unused and older-but-supported are
different states:

- **Current, billed**: typed
  `/v2/whatsapp/templates/emergency/{activation,simulacrum}` sends and the
  `/v2/credits/{code}` reads.
- **Older contract, still supported**: `/v2/whatsapp/templates/send` (abort) and
  `{code,message}` error bodies. The BFF still consumes them; they are not
  deprecated and must not be removed without migrating the caller.
- **Unused fallbacks**: `body.attributes` / `body.data` credit envelopes. The
  current API does not emit them and they are safe to delete.
- Before deleting anything, check for in-repo consumers. "Unused" and
  "deprecated" are not synonyms.
