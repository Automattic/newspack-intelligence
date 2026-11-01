<?php
/**
 * Seed_Refused: `Ontology_Seed::seed()` refusing to run while the projector
 * has not read the ontology stream to its end, the one failure a caller
 * reports as a refusal rather than letting propagate.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

/**
 * Thrown by `Ontology_Seed::seed()` before it reads or writes anything.
 */
final class Seed_Refused extends \RuntimeException {}
