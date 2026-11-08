# <?php \defined( 'ABSPATH' ) || exit; ?>
# Everything Intelligence runs but its collect: the streams, the projection,
# resolution, re-resolution, the watcher and the Ask. A composition includes
# this, then its collect, then newspack-intelligence-deployment last, and sets
# its own frontmatter, which a part cannot.
var num_partitions = 1
include topic-probe
include newspack-intelligence-streams
include newspack-intelligence-project
include newspack-intelligence-resolve
# Re-resolution spans two files: the reresolver reads the ontology through
# its own Consumer, which it checkpoints with, and appends the items to
# re-resolve to intel:reresolve.
make_node Consumer intel:ontology:reresolve <config:logs_dir>/intel-ontology.p{partition} <config:offsets_dir>/<topology>.intel-ontology-reresolve.p{partition} <config:deadletter_dir>/<topology>.intel-ontology-reresolve.p{partition}
make_node Reresolver reresolver intel:reresolve
command_node intel:ontology:reresolve:config add_snapshot_node reresolver
command_node intel:ontology:reresolve:config set_multi_writer true
connect_node intel:ontology:reresolve reresolver
# The watcher reads the projection as each watch's owner every five minutes
# and writes its matches and marks to intel:writer, which the streams file
# declares.
make_node Watcher watcher intel:writer
# The Ask reads each question from the asks stream through its own Consumer,
# plans and answers it through intel:llm as new work, reads as the asker, and
# writes the outcome to intel:writer; request-side appends share the stream.
make_node Consumer intel:asks:ask <config:logs_dir>/intel-asks.p{partition} <config:offsets_dir>/<topology>.intel-asks-ask.p{partition} <config:deadletter_dir>/<topology>.intel-asks-ask.p{partition}
command_node intel:asks:ask:config set_multi_writer true
make_node Ask ask intel:writer intel:llm
connect_node intel:asks:ask ask
secure
