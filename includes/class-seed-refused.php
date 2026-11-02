<?php
/**
 * Seed_Refused: `Ontology_Seed` refusing a seed file it cannot find or
 * read, naming the field at fault, or refusing to run while the projector
 * has not read the ontology stream to its end; the failures a caller
 * reports as a refusal rather than letting propagate.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

/**
 * Thrown by `Ontology_Seed::load()` and `seed()` before anything is written.
 */
final class Seed_Refused extends \RuntimeException {}
