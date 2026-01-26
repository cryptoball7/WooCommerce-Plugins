# Architecture Non-Decisions

This document records the **explicit non-decisions** for the Agent Commerce architecture.

These are areas that are intentionally *not* decided yet. The absence of a decision is deliberate and protective. Any attempt to implicitly decide these through implementation should be treated as architectural drift.

---

## Purpose of this document

Agent commerce is an emerging space. Premature standardization creates lock-in, fragility, and unnecessary conflict.

This document exists to:
- Prevent accidental overreach
- Reduce speculative debates
- Preserve flexibility
- Make deferral an explicit choice

---

## Non-decision 1: Agent protocol standard

**Not decided**  
The system does not mandate a single agent protocol (e.g. ACP, UCP, proprietary formats).

**Reason for deferral**
- No protocol has achieved clear dominance
- Standards are evolving rapidly
- Early convergence would limit adoption

**Architectural consequence**
- Protocol adapters translate external formats into internal schemas
- Internal APIs remain protocol-agnostic

---

## Non-decision 2: Agent identity model

**Not decided**  
The architecture does not define a global or cryptographic agent identity system.

**Reason for deferral**
- Identity requirements vary by merchant, platform, and jurisdiction
- Over-specification risks excluding valid use cases

**Current stance**
- Identity must be explicit
- Identity format is flexible
- Trust models are out of scope

---

## Non-decision 3: Trust, reputation, or scoring systems

**Not decided**  
No trust, reputation, or scoring mechanism is defined for agents.

**Reason for deferral**
- Trust is highly contextual
- Scoring systems are often controversial
- Premature scoring introduces governance risk

**Implication**
- Plugins must not infer trustworthiness
- Authorization is scope-based only

---

## Non-decision 4: Payment rail specialization

**Not decided**  
The system does not define or optimize for specific payment rails.

**Reason for deferral**
- WooCommerce supports many gateways
- Payment innovation continues rapidly
- Merchants already have established payment stacks

**Current stance**
- Payments are executed via WooCommerce
- Agent plugins remain payment-agnostic

---

## Non-decision 5: Negotiation or dynamic pricing

**Not decided**  
The architecture does not support agent-driven negotiation or dynamic pricing rules.

**Reason for deferral**
- Pricing strategy is merchant-specific
- Negotiation introduces complex state and incentives
- Regulatory and fairness concerns vary by region

**Implication**
- Prices are treated as quoted facts
- Plugins must not negotiate or adjust prices autonomously

---

## Non-decision 6: Universal checkout flow

**Not decided**  
There is no single mandated agent checkout flow.

**Reason for deferral**
- Checkout complexity varies widely
- Merchants customize flows heavily
- Agents may operate synchronously or asynchronously

**Architectural stance**
- Checkout is decomposed into primitives
- Higher-level flows may emerge later

---

## Non-decision 7: Dispute resolution automation

**Not decided**  
The system does not automate disputes, refunds, or chargebacks.

**Reason for deferral**
- Legal and regulatory differences
- Merchant-specific policies
- High risk of unintended consequences

**Current stance**
- Events provide audit trails
- Humans remain in the loop

---

## Non-decision 8: Agent autonomy level

**Not decided**  
The architecture does not define how autonomous an agent may be.

**Reason for deferral**
- Autonomy expectations differ by merchant
- Legal responsibility varies

**Implication**
- Authorization scopes define capability, not intent
- Autonomy policies belong outside core plugins

---

## Non-decision 9: UI or merchant experience standards

**Not decided**  
The system does not mandate merchant-facing UI patterns for agent configuration.

**Reason for deferral**
- Merchants vary in sophistication
- WordPress admin UX evolves

**Current stance**
- Plugins may provide minimal configuration
- UX consistency is a future concern

---

## Non-decision 10: Data retention and governance policies

**Not decided**  
The architecture does not define data retention, storage duration, or governance policies.

**Reason for deferral**
- Jurisdictional variability
- Merchant responsibility

**Implication**
- Events are emitted, not governed
- Storage policies live outside core architecture

---

## Guardrail principle

> **Absence of a decision is not a gap to be filled by implementation.**

If a proposed feature implicitly decides any item above, it must:
1. Be rejected, or
2. Trigger an explicit update to this document

---

## How this document should evolve

- Items may move from non-decisions to decisions as the ecosystem matures
- Movement should be intentional and justified
- The default bias is toward continued deferral

This document exists to protect long-term flexibility and architectural integrity.

