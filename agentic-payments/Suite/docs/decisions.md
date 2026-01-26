# Architecture Decisions

This document records the **explicit architectural decisions** made for the Agent Commerce plugin ecosystem.

These decisions are intentional, durable, and expected to hold across multiple plugin versions. Any proposal that contradicts a decision in this document requires a deliberate review and update.

---

## Decision 1: Multi-plugin architecture (not all-in-one)

**Decision**  
Agent Commerce is implemented as a **suite of independent WooCommerce plugins**, not a single monolithic plugin.

**Rationale**
- WooCommerce and WordPress are inherently plugin-oriented
- Merchants have different readiness levels and needs
- Independent plugins reduce upgrade risk
- Narrow scope improves testability and security
- Features can evolve at different speeds

**Implications**
- Plugins must have clear, non-overlapping responsibilities
- Plugins can be installed or removed independently
- Bundling may occur later, but modularity remains internal

---

## Decision 2: Agent Core as shared infrastructure

**Decision**  
A dedicated **Agent Core** plugin provides shared infrastructure used by all other Agent Commerce plugins.

**Agent Core responsibilities**
- Canonical API namespace
- Schema loading and validation
- Agent identity handling
- Authorization and scope enforcement
- Event spine
- Shared utilities

**Non-responsibilities**
- No checkout logic
- No product mutation
- No payments
- No business rules

**Rationale**
Separating infrastructure from features prevents coupling, duplication, and accidental privilege escalation.

---

## Decision 3: Agents never access WooCommerce directly

**Decision**  
AI agents must not interact directly with WooCommerce REST or Store APIs.

All agent interactions flow through:
```
/wp-json/agent-commerce/{version}
```

**Rationale**
- Shields WooCommerce internals from protocol churn
- Enables stable, agent-friendly contracts
- Allows WooCommerce to be treated as an implementation detail
- Improves security and observability

---

## Decision 4: Schemas are contracts

**Decision**  
All public inputs and outputs are defined by **explicit, versioned schemas**.

**Rationale**
- Enables independent plugin development
- Prevents silent breaking changes
- Supports protocol adapters and tooling
- Makes behavior auditable and testable

**Implications**
- Plugins consume schemas, not redefine them
- Schema changes require versioning
- WooCommerce objects are never exposed directly

---

## Decision 5: Determinism over cleverness

**Decision**  
Agent-facing APIs must be deterministic and safe to retry.

**Rationale**
- Agents rely on predictability
- Retries are common in automated systems
- Determinism simplifies debugging and dispute resolution

**Implications**
- Idempotency is required for state-changing operations
- No time-based randomness
- No hidden personalization or heuristics

---

## Decision 6: Centralized authorization

**Decision**  
Authorization and scope enforcement are handled centrally by Agent Core.

Plugins:
- Declare required scopes
- Do not implement authorization logic

**Rationale**
- Avoids duplicated security logic
- Ensures consistent enforcement
- Simplifies audits and reviews

---

## Decision 7: Explicit agent identity

**Decision**  
Agent identity is always **explicitly provided**, never inferred.

Each request includes:
- Agent identifier
- Agent provider
- Acting entity (user or organization)
- Declared capabilities

**Rationale**
- Prevents ambiguity during disputes
- Supports auditability
- Avoids guessing intent or authority

---

## Decision 8: Protocol independence

**Decision**  
The internal system is **protocol-agnostic**.

External protocols (e.g. ACP, UCP) are handled via adapters that translate into internal schemas.

**Rationale**
- No single protocol has emerged as dominant
- Standards will evolve
- Avoids lock-in

---

## Decision 9: Events are signals, not commands

**Decision**  
The event system emits **append-only signals**.

Events:
- Are not used to control request flow
- Do not block or mutate state
- Exist for audit, analytics, and governance

**Rationale**
This keeps the system observable without introducing hidden coupling or side effects.

---

## Decision 10: Conservative v1 scope

**Decision**  
The initial release prioritizes **clarity and safety** over feature completeness.

**Rationale**
- Agent commerce is still emerging
- Early assumptions are expensive to reverse
- Incremental adoption reduces merchant risk

**Implications**
- Advanced features are deferred
- Escape hatches are preferred over rigid rules

---

## How to use this document

- Use this document to evaluate new features and plugins
- If a proposal conflicts with a decision, update this document explicitly
- Avoid undocumented “implicit decisions”

This document exists to keep the architecture intentional as the system evolves.
