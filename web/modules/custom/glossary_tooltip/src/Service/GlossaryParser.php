<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;

/**
 * Builds and processes the glossary term index.
 *
 * The service is split into two layers:
 *
 *  - getTermIndex(): returns a cached array of glossary terms used both
 *    for matching and for rendering tooltips. The array is sorted by
 *    name length DESC so longer phrases match before shorter ones
 *    (e.g. "machine learning" wins over "learning").
 *
 *  - process($html): walks an HTML fragment with DOM, runs chunked
 *    regex passes per text node, and wraps matches with tooltip markup.
 *
 * Why this design:
 *  - A single in-memory index is built once per cache lifetime and
 *    invalidated via the "taxonomy_term_list:glossary" cache tag.
 *  - PCRE alternations grow linearly with the number of terms; very
 *    large vocabularies are chunked (CHUNK_SIZE) to stay below PCRE
 *    compile limits and to keep individual passes fast.
 *  - DOM walking avoids the classic "regex inside HTML" pitfalls
 *    (matching inside attributes, breaking nested tags, double-wrapping).
 *  - Only the FIRST occurrence of any given term per processed text
 *    is wrapped. This keeps the page readable when the same word
 *    repeats many times in a long article and bounds the work done.
 */
final class GlossaryParser {

  /**
   * Vocabulary id of the glossary.
   */
  public const VID = 'glossary';

  /**
   * Cache key for the compiled term index.
   */
  private const CACHE_CID = 'glossary_tooltip:index';

  /**
   * Cache tag invalidated whenever a glossary term changes.
   */
  public const CACHE_TAG = 'taxonomy_term_list:' . self::VID;

  /**
   * Max characters of the description shown in the tooltip body.
   */
  public const TOOLTIP_LIMIT = 100;

  /**
   * Max number of terms per compiled regex chunk.
   *
   * Keeps each alternation compileable on default PCRE limits even
   * for very large vocabularies.
   */
  private const CHUNK_SIZE = 500;

  /**
   * In-request memoisation of the term index.
   *
   * @var array<string,array{tid:int,name:string,description:string,truncated:bool,url:string}>|null
   */
  private ?array $indexStatic = NULL;

  /**
   * In-request memoisation of the compiled regex chunks.
   *
   * @var string[]|null
   */
  private ?array $patternsStatic = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns the cached glossary index.
   *
   * @return array<string,array{tid:int,name:string,description:string,truncated:bool,url:string}>
   *   Keys are the lower-cased term names; values carry render-ready data.
   */
  public function getTermIndex(): array {
    if ($this->indexStatic !== NULL) {
      return $this->indexStatic;
    }

    $cached = $this->cache->get(self::CACHE_CID);
    if ($cached && is_array($cached->data)) {
      return $this->indexStatic = $cached->data;
    }

    $index = $this->buildTermIndex();

    $this->cache->set(
      self::CACHE_CID,
      $index,
      CacheBackendInterface::CACHE_PERMANENT,
      [self::CACHE_TAG],
    );

    return $this->indexStatic = $index;
  }

  /**
   * Returns the compiled regex chunks matching all glossary terms.
   *
   * @return string[]
   *   Array of PCRE patterns (already delimited and flagged).
   */
  public function getPatterns(): array {
    if ($this->patternsStatic !== NULL) {
      return $this->patternsStatic;
    }

    $index = $this->getTermIndex();
    if (!$index) {
      return $this->patternsStatic = [];
    }

    $names = array_map(static fn(array $row): string => $row['name'], $index);
    // Already sorted longest-first by buildTermIndex().
    $patterns = [];
    foreach (array_chunk($names, self::CHUNK_SIZE) as $chunk) {
      $escaped = array_map(static fn(string $n): string => preg_quote($n, '/'), $chunk);
      // Unicode-aware word boundary: previous/next char must NOT be a letter
      // or digit so that "Drupal" matches but "predrupalize" doesn't.
      $patterns[] = '/(?<![\p{L}\p{N}_])(' . implode('|', $escaped) . ')(?![\p{L}\p{N}_])/iu';
    }

    return $this->patternsStatic = $patterns;
  }

  /**
   * Resets in-request memoization. Used by hooks after term changes.
   */
  public function resetStatic(): void {
    $this->indexStatic = NULL;
    $this->patternsStatic = NULL;
  }

  /**
   * Wraps glossary matches inside an HTML fragment.
   *
   * @param string $html
   *   Input HTML.
   *
   * @return string
   *   HTML with matched terms wrapped in tooltip markup.
   */
  public function process(string $html): string {
    if ($html === '' || trim($html) === '') {
      return $html;
    }
    $index = $this->getTermIndex();
    if (!$index) {
      return $html;
    }
    $patterns = $this->getPatterns();
    if (!$patterns) {
      return $html;
    }

    try {
      $dom = Html::load($html);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('glossary_tooltip')->warning(
        'Failed to parse HTML for glossary processing: @msg',
        ['@msg' => $e->getMessage()],
      );
      return $html;
    }

    // Track which term ids were already wrapped in this run so we
    // emit each tooltip only once per processed fragment.
    $usedTids = [];
    $this->walk($dom->documentElement, $dom, $patterns, $index, $usedTids);

    return Html::serialize($dom);
  }

  /**
   * Recursive DOM walker.
   */
  private function walk(
    \DOMNode $node,
    \DOMDocument $dom,
    array $patterns,
    array $index,
    array &$usedTids,
  ): void {
    // Element gating: skip nodes whose textual content must remain intact.
    static $skipTags = [
      'a' => TRUE, 'code' => TRUE, 'pre' => TRUE, 'kbd' => TRUE,
      'script' => TRUE, 'style' => TRUE, 'textarea' => TRUE,
      'h1' => TRUE, 'h2' => TRUE, 'h3' => TRUE,
      'h4' => TRUE, 'h5' => TRUE, 'h6' => TRUE,
    ];

    if ($node instanceof \DOMElement) {
      $tag = strtolower($node->nodeName);
      if (isset($skipTags[$tag])) {
        return;
      }
      // Never recurse into an already-wrapped tooltip.
      if (
        $node->hasAttribute('class')
        && str_contains($node->getAttribute('class'), 'glossary-tooltip')
      ) {
        return;
      }
    }

    // Iterate over a static snapshot — we mutate childNodes below.
    $children = iterator_to_array($node->childNodes);
    foreach ($children as $child) {
      if ($child instanceof \DOMText) {
        $this->processTextNode($child, $dom, $patterns, $index, $usedTids);
      }
      elseif ($child instanceof \DOMElement) {
        $this->walk($child, $dom, $patterns, $index, $usedTids);
      }
    }
  }

  /**
   * Runs the regex chunks against one text node and rewrites it.
   */
  private function processTextNode(
    \DOMText $textNode,
    \DOMDocument $dom,
    array $patterns,
    array $index,
    array &$usedTids,
  ): void {
    $text = $textNode->nodeValue ?? '';
    if ($text === '' || trim($text) === '') {
      return;
    }

    $matched = FALSE;
    // Pass-through accumulator of HTML fragments.
    $output = '';
    $cursor = 0;

    foreach ($patterns as $pattern) {
      // Collect all matches with offsets across the (still untouched) text.
      if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        continue;
      }
      foreach ($matches as $hit) {
        [$word, $offset] = $hit[1];
        $key = mb_strtolower($word);
        if (!isset($index[$key])) {
          continue;
        }
        $tid = $index[$key]['tid'];
        if (isset($usedTids[$tid])) {
          continue;
        }
        if ($offset < $cursor) {
          // Overlap with an already-emitted match from a previous pattern;
          // skip to keep output well-formed.
          continue;
        }
        $usedTids[$tid] = TRUE;
        $matched = TRUE;

        // Emit text up to the match.
        if ($offset > $cursor) {
          $output .= Html::escape(substr($text, $cursor, $offset - $cursor));
        }
        $output .= $this->renderTooltip($word, $index[$key]);
        $cursor = $offset + strlen($word);
      }
    }

    if (!$matched) {
      return;
    }

    if ($cursor < strlen($text)) {
      $output .= Html::escape(substr($text, $cursor));
    }

    // Replace the text node with the rendered fragment.
    $fragment = $dom->createDocumentFragment();
    // Wrap in a span so appendXML always has a single root context;
    // we then unwrap the children into the parent.
    @$fragment->appendXML('<span>' . $output . '</span>');
    if (!$fragment->hasChildNodes()) {
      return;
    }
    $wrapper = $fragment->firstChild;
    $parent = $textNode->parentNode;
    while ($wrapper->firstChild) {
      $parent->insertBefore($wrapper->firstChild, $textNode);
    }
    $parent->removeChild($textNode);
  }

  /**
   * Renders the tooltip markup for a single match.
   */
  private function renderTooltip(string $matchedWord, array $row): string {
    $description = $row['description'];
    $linkAttr = $row['truncated'] ? ' data-read-more-url="' . Html::escape($row['url']) . '"' : '';
    return '<span class="glossary-tooltip" tabindex="0" role="button" aria-label="' . Html::escape($row['name']) . '" data-description="' . Html::escape($description) . '"' . $linkAttr . '>'
      . Html::escape($matchedWord)
      . '</span>';
  }

  /**
   * Builds the index from storage.
   *
   * Loads terms in batches so memory usage stays bounded for
   * very large vocabularies.
   *
   * @return array<string,array{tid:int,name:string,description:string,truncated:bool,url:string}>
   */
  private function buildTermIndex(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', self::VID)
      ->condition('status', 1)
      ->execute();
    if (!$tids) {
      return [];
    }

    $index = [];
    foreach (array_chunk($tids, 200) as $chunk) {
      /** @var \Drupal\taxonomy\TermInterface[] $terms */
      $terms = $storage->loadMultiple($chunk);
      foreach ($terms as $term) {
        $name = trim((string) $term->label());
        if ($name === '') {
          continue;
        }
        $raw = '';
        if ($term->hasField('field_description') && !$term->get('field_description')->isEmpty()) {
          $raw = (string) $term->get('field_description')->value;
        }
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)) ?? '');
        $truncated = mb_strlen($plain) > self::TOOLTIP_LIMIT;
        $display = $truncated
          ? Unicode::truncate($plain, self::TOOLTIP_LIMIT, TRUE, TRUE)
          : $plain;

        $url = $term->toUrl('canonical', ['absolute' => FALSE])->toString();

        $key = mb_strtolower($name);
        $index[$key] = [
          'tid' => (int) $term->id(),
          'name' => $name,
          'description' => $display,
          'truncated' => $truncated,
          'url' => $url,
        ];
      }
      // Free memory between batches.
      $storage->resetCache($chunk);
    }

    // Sort by name length DESC so multi-word terms win over their prefixes.
    uksort($index, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

    return $index;
  }

}
