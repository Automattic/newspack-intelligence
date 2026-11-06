# <?php \defined( 'ABSPATH' ) || exit; ?>
# The sources that run with no credential: GitHub, whose public API answers
# without its Vault entry, and the feeds, each recording item_recorded events
# at its tier into intel:writer.
var num_partitions = 1
include topic-probe
include newspack-intelligence-streams
make_node Github_Source github public github
cmd github:config set_interval 900
make_node Feed_Source feed public
cmd feed:config set_interval 1800
cmd feed:config set_client_feeds true
# A second feed node, hourly, for searches standing in for absent feeds.
make_node Feed_Source news public
cmd news:config set_interval 3600
connect_node github intel:writer
connect_node feed intel:writer
connect_node news intel:writer
secure
