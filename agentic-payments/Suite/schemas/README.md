# Agent Commerce Schemas

This directory contains the **canonical schema definitions** used across the Agent Commerce ecosystem.

Schemas are the primary contracts between:
- AI agents
- Agent Commerce plugins
- External protocol adapters

They are treated as **stable, versioned interfaces**, not implementation details.

---

## Guiding principles

### Schemas are contracts
Schemas define *what* data looks like, not *how* it is produced or used.

- Plugins consume schemas; they do not redefine them
- WooCommerce objects are never exposed directly
- Schemas describe observable facts, not business logic or policy

---

### Schemas are minimal by design
Schemas intentionally expose the smallest useful surface area.

This:
- Improves stability
- Reduces accidental coupling
- Makes schemas easier for agents to reason about

If information is not required for agent reasoning, it likely does not belong in v1.

---

## Versioning strategy

Schemas are versioned using **major versions only**.

```
schemas/
  v1/
  v2/
```

### Compatibility rules

Within a major version:
- Fields may be added if optional
- Meanings of existing fields must not change
- Required fields must not be removed

Breaking changes require a new major version.

Silent behavior changes are not allowed.

---

## Extension rules

Schemas may be extended in limited ways:

- Optional fields may be added
- `metadata` objects may contain arbitrary key/value pairs

Extensions must:
- Be clearly non-authoritative
- Never change the meaning of core fields
- Never be required for correct behavior

Plugins must not depend on extensions for correctness.

---

## Validation requirements

All agent-facing endpoints must:

- Validate request payloads against schemas
- Validate response payloads before returning them
- Reject invalid payloads explicitly

Validation failures must be deterministic and descriptive.

---

## Schema ownership

Each schema has a single, clear purpose:

- `agent_identity` → who is acting
- `agent_context` → under what conditions
- `product_summary` → what exists in the catalog

Schemas must not overlap responsibilities.

If new concerns arise, define a new schema rather than overloading an existing one.

---

## Relationship to protocols

External agent protocols (e.g. ACP, UCP) do not map directly to schemas.

Instead:
- Protocol adapters translate protocol-specific payloads
- Adapters produce canonical schema-compliant objects

Schemas remain protocol-agnostic.

---

## Relationship to plugins

- Schemas live outside plugins
- Plugins reference schemas by version
- Plugins must not fork or inline schema definitions

This ensures consistency across the ecosystem.

---

## Testing and tooling

Schemas are used by:
- Runtime validation
- Mock agents
- Fixtures and replay tools

Schema changes should include:
- Updated fixtures
- Updated validation tests

---

## Philosophy

> **Schemas should change slowly and deliberately.**

When in doubt:
- Prefer adding a new schema
- Prefer optional fields over required ones
- Prefer explicit versioning over clever compatibility

Schemas are the foundation of agent interoperability. Treat them accordingly.

