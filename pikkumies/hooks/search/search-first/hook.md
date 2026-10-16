---
name: search-first
event: pre_llm_call
description: Tells the agent to search lessons and documentation first and lists local titles that match the user's message.
failClosed: false
timeout: 10
---
First turn: the search instruction plus matching titles. Later turns: only for a new question, which also re-arms `search-first-gate`. Titles, trust and paths only, at most 1500 bytes (ADR-0011, ADR-0028).
