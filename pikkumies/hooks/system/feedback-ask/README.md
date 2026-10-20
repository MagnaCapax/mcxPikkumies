# feedback-ask

Runs before each turn (`pre_llm_call`) and, rarely, adds one sentence telling the agent to end its answer with a short question: would you like to send Pulsed Media feedback about this session? ([ADR-0056](../../../../docs/adr/0056-feedback-what-pikkumies-sends-to-pulsed-media.md), amendment 2026-10-19)

- **Off unless you turn it on:** the setting `feedback.ask` is off by default. You can turn it on at the first interactive start (one question, Enter means no) or by asking the agent, which runs since you just asked for exactly this ([ADR-0073](../../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md)). Saying "never ask again" to the question, or the pikkumies-configure skill, turns it off.
- **When:** only in your own session on a terminal (not in scheduled jobs, chat gateways or agents started by agents; `--yolo`, the default run mode, makes no difference), only once the `pikkumies` you started has run for 45 minutes and used 500,000 tokens (compactions included), never twice in one session, in about 1 such session in 5, and then not again for 14 days. The other long sessions get the one-line rating when Pikkumies closes, which shares the same budget ([feedback README](../../../tools/system/feedback/README.md)). These numbers are starting values.
- **What it costs:** one sentence of about 70 tokens in the turn it is asked, on your credits.
- **What it sends:** nothing. A yes starts the `/feedback` steps; the feedback itself is sent only when what you asked for covers it (your yes to that question, or your own request); the ask policy reviews the send, and otherwise it is not sent and the agent says so ([ADR-0101](../../../../docs/adr/0101-refuse-and-report-never-ask.md)).

Fails open: an error, or a state directory it cannot write, only means no question.
