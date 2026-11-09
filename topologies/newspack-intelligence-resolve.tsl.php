# <?php \defined( 'ABSPATH' ) || exit; ?>
# Resolution and extraction: each recorded item linked to the entities it is
# about, then extracted. The Consumer reads the whole items stream; the
# resolver acts on item_recorded alone, writes its links and candidates to
# intel:writer and forwards each resolution to the extractor, which writes the
# extraction and its claims to intel:writer and forwards the item to the
# deduper, which writes each cluster decision to intel:writer. The Consumer
# checkpoints the deduper's lookup with its cursor. intel:reresolve:resolve
# feeds the resolver each item the reresolver names, so an item whose
# resolution throws dead-letters alone. Both Consumers hand the resolver one
# item a poll. Every model call, the resolver's NER step and each extraction,
# goes through intel:llm, which queues it in its own Table and answers the
# caller when the model does, so no call blocks the worker; the decision
# model then reviews an unrestricted item's extraction, judging whether a
# client's own article is about the client and picking among offered themes.
var num_partitions = 1
include topic-probe
include newspack-intelligence-streams
make_node Consumer intel:items:resolve <config:logs_dir>/intel-items.p{partition} <config:offsets_dir>/<topology>.intel-items-resolve.p{partition} <config:deadletter_dir>/<topology>.intel-items-resolve.p{partition}
command_node intel:items:resolve:config set_line_mode true
# A deployment's lists name the protocols, the url, the two models and more.
make_node LLM_Call intel:llm ai-proxy
cmd intel:llm:config set_concurrency 4
make_node Resolver resolver intel:writer intel:later intel:llm
make_node Extractor extractor intel:writer intel:later intel:llm
make_node Deduper deduper intel:writer
command_node intel:items:resolve:config add_snapshot_node deduper
make_node Consumer intel:reresolve:resolve <config:logs_dir>/intel-reresolve.p{partition} <config:offsets_dir>/<topology>.intel-reresolve-resolve.p{partition} <config:deadletter_dir>/<topology>.intel-reresolve-resolve.p{partition}
command_node intel:reresolve:resolve:config set_line_mode true
connect_node intel:items:resolve resolver
connect_node intel:reresolve:resolve resolver
connect_node resolver extractor
connect_node extractor deduper
secure
