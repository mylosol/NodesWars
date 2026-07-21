# **Engineering Requirements Document (ERD): Project "Nodes Wars"**

**Target Audience:** Claude Dispatch & Claude Code Agents  
**Project Persona:** Lead Technical Project Manager

## **1\. Executive Brief & Architecture Paradigm**

This document establishes the technical blueprint for **Nodes Wars**, an asynchronous, turn-based, location-based tactical strategy simulation. The application is built as a **Mobile-First Progressive Web App (PWA)** designed to operate in extreme, infrastructure-free environments using peer-to-peer (P2P) **LoRa radio networks** via Meshtastic hardware.

### **Core Architectural Pillars**

* **Local-First Paradigm:** Local device storage acts as the primary source of truth. There is no central real-time database dependency during live match execution.  
* **Append-Only Client Ledger:** Every player choice (Strike, Fortify, Bounty) generates an immutable, cryptographically signed transaction block appended to a browser-bound ledger (IndexedDB).  
* **Asynchronous State Convergence:** Nodes evaluate the sequential history of signed blocks independently. Identical deterministic state machines ensure match alignment across all peers.

## **2\. Multi-Transport Sync Matrix**

The synchronization layer must dynamically adapt to available networking interfaces using a tiered matrix:

| Transport Layer | Channel / Mechanism | Purpose | Data Payload |
| :---- | :---- | :---- | :---- |
|  **LoRa Mesh (Off-Grid)** | Dedicated Private Port (portnum \= 2026) | Live P2P state changes, action broadcasts, local heartbeat syncs. | Compact binary JSON blobs containing signatures, nonces, coordinates. |
|  **LoRa Admin Sync** | Secondary Local Sync Requests | Peer-to-peer transaction history replay when out of telemetry range. | Sequence IDs and missing ledger hashes. |
|  **Cellular / Wi-Fi (Hybrid)** | Secure WebSocket / API Gateway Bridge | Asynchronous background backup, anti-cheat validation, cross-mesh leaderboards. | Bulk transaction log batch updates. |

### **Private Port Rules & Recovery**

* **Port Constraints:** All active gameplay traffic must target portnum \= 2026 to keep the standard LongFast public channel clean.  
* **Sequence Tracking:** Every action gets a monotonically increasing Sequence Number.  
* **Heartbeat Sync:** PWAs must periodically broadcast a lightweight heartbeat containing their latest sequence ID. Out-of-sync nodes detecting gaps must request a point-to-point history replay from adjacent peers.

## **3\. Hardware Integration & Telemetry**

### **Background Location Offloading**

Due to aggressive iOS and Android background execution limits over Web Bluetooth Low Energy (BLE), background tracking is entirely offloaded to the Meshtastic hardware:

1. **Configuration Profile:** On initialization, the PWA pushes an administrative profile over BLE to the paired node.  
2. **Smart Position Broadcasting:** The hardware microcontroller independently logs and transmits physical coordinates to the mesh at set thresholds (every 50 meters moved or 15 minutes stationary).  
3. **Eligibility Filter:** Standalone routers, static network nodes, and un-positioned assets lacking valid hardware GPS streams are programmatically excluded from the match.

### **Timeline Reconciliation**

When the phone screen is asleep, the physical node's internal rolling packet history caches incoming strike payloads. The exact moment the user opens the PWA dashboard, the app must drain the node's ring-buffer via BLE serial, merge it with server background packets, and run a timeline replay.

## **4\. Core Mechanics & Mathematical Engine**

### **Command Bases & Tactical Map**

* **Base Anchoring:** A player's Command Base is established at the exact telemetry coordinates provided by their node's hardware GPS.  
* **Fog of War:** A Leaflet-API-powered map rendering is centered on the player. A graphical fog obscures all geometry beyond a maximum radius of 1,000 meters from the device. Enemy positions are hidden.

### **Turn Economy & Progression**

* Actions draw from a fixed move pool that replenishes automatically on a global timer (e.g., 5 minutes per move).  
* Players spend moves to **Attack** or **Fortify**.  
* Progression awards Experience Points (XP) and Coins. Scaling levels increase maximum move pool capacities and require exponentially scaling XP.

### **The Artillery Trajectory Engine**

To fire a strike, a user inputs a Weapon, Ammunition type, optional Booster, direction vector (0°–360°), angle of attack, and muzzle velocity (1–100) via a lower $\\frac{1}{3}$ sliding pane interface.  
Execution instantly fires the following sequence:

1. Deducts 1 move and 1 ammunition unit.  
2. Computes an orbital trajectory producing a target GPS destination and a hardcoded blast radius.  
3. Broadcasts an encrypted LoRa payload.  
4. Renders an in-app visual tracer projectile animation and explosion graphic over the map.

### **Diminishing Returns Loot Formula**

To protect Non-Played Nodes (NPNs—unengaged nodes mapped by the server) from high-level farming, rewards scale downward based on player level delta:

$$\\text{Reward Multiplier} \= \\max\\left(0, 1.0 \- \\frac{\\text{Player Level} \- 1}{10}\\right)$$

* **Level 1:** 100% rewards  
* **Level 5:** 60% rewards  
* **Level 11+:** 0% XP/Coins (Mechanical shield depletion value only)

## **5\. Verification Framework & Cryptographic Security**

### **3-Tier Hit Scoring Loop**

Verification functions off-grid via a split reward sequence totaling 100% of a move's calculated reward value:

* **Base Strike (Instant):** 20% of max XP is locked to the profile upon broadcasting.  
* **Suspected Hit (Phase 1):** 40% of max XP is awarded when the ledger validates that the blast radius intersected the target's last known position.  
* **Confirmed Hit (Phase 2):** The remaining 40% of max XP is unlocked via client check-in, asymmetric witness bounty verification, or a recon drone query log lookup.

### **Fallback Verification Mechanics**

* **Asymmetric Bounty System:** If the target misses a packet, any third-party node routing mesh traffic can clear the backlog. If their local "radar cache" proves the defender was inside the blast zone during the timestamp, they sign and broadcast a WITNESS\_BOUNTY payload. The third party wins a "Data Broker" credit bounty.  
* **Recon Drone:** If an attack remains unconfirmed for 24 hours, an active query fires a DATA\_REQUEST payload to neighbor node caches off-grid, while checking central web backend silent logs simultaneously if cellular data is active.

### **Anti-Cheat Specifications**

* **Payload Encryption:** All application layer JSON payloads must be encrypted using **AES-GCM** via the browser's native **Web Crypto API**.  
* **QR Proximity Handshake:** To initialize offline matches without central key authority, a Game Master PWA generates an AES-GCM 256-bit symmetric passphrase into an on-screen QR code. Joining players scan this camera-to-screen to ingest the session key locally.  
* **Replay Protection:** Sub-5-minute TTL constraints on packets. Incremental per-player cryptographic nonces track action counts to block duplicate processing.

## **6\. Implementation Mandate for Claude Code**

### **⚠️ CRITICAL INSTRUCTIONS FOR AGENT DELEGATION**

Prior to generating code or scaffolding features, you are required to perform a comprehensive design sanity check. Flag any edge cases, suggest modern implementation standards, and explicitly formulate answers to the challenges detailed below.

### **Technical Inconsistencies & Challenges to Solve**

1. **IndexedDB State Merging Conflict:** Since the network model is local-first append-only, how will Claude Code handle fork-resolution if two conflicting out-of-order logs are replayed over BLE after a long offline period? Draft a deterministic resolution strategy (e.g., Vector Clocks or CRDTs).  
2. **LoRa MTU Fragmentation Constraints:** Standard Meshtastic application payloads are highly restrictive regarding Maximum Transmission Units (MTU). The base specification references "LoRa Binary JSON". Claude Code must evaluate if the specified JSON structures will exceed packet limits and suggest a binary compression scheme (such as Protocol Buffers or CBOR) to fit payloads into single packets.  
3. **Browser Cryptography Limitations:** The Web Crypto API requires a secure context (https:// or localhost). Since this app runs off-grid over a local PWA deployment, outline how local key execution will be preserved without breaking browser security contexts if accessed directly via local mesh IPs or custom domains without active DNS routing.  
4. **UX Freeze Mitigation:** Reading the hardware ring-buffer over BLE serial is a slow process. Claude Code must isolate this operation into a Web Worker to ensure the UI overlay remains responsive.

### **Codebase Best Practices**

* Write modular, highly typed TypeScript modules for the local ledger implementation.  
* Enforce structural isolation between the offline layout layer (Leaflet API) and the hardware ingress BLE interface layer.  
* Ensure the trajectory calculation engine uses clear deterministic floating-point precision coordinates so that matches evaluate identically across different mobile devices.

Proceed with parsing the specifications, compiling structural recommendations, and posing clarifying questions regarding your specific implementation patterns.