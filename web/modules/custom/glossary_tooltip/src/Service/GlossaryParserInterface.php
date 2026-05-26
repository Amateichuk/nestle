<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Service;

/**
 * Public contract for the glossary tooltip parser.
 *
 * Decoupling consumers (the filter plugin, tests, future custom code)
 * from the concrete class keeps {@see GlossaryParser} free to evolve
 * its internals (private helpers, caching strategy) without breaking
 * downstream code, and allows the service to be doubled in unit tests.
 */
interface GlossaryParserInterface {

  /**
   * Wraps glossary matches inside an HTML fragment.
   *
   * @param string $html
   *   Input HTML.
   *
   * @return string
   *   HTML with matched glossary terms wrapped in tooltip markup.
   *   When the vocabulary is empty the input is returned unchanged.
   */
  public function process(string $html): string;

  /**
   * Resets in-request memoisation of the term index and patterns.
   *
   * Persistent cache invalidation is handled by cache tags; this is
   * only needed when the same request mutates terms and then renders
   * filtered content.
   */
  public function resetStatic(): void;

}
