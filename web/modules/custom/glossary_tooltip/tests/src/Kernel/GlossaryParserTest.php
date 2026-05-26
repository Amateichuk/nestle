<?php

declare(strict_types=1);

namespace Drupal\Tests\glossary_tooltip\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\glossary_tooltip\Service\GlossaryParser;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Tests for the glossary parser service.
 *
 * Exercises the real service against the entity / cache / config
 * stack rather than a stack of mocks: the value of the test is in
 * detecting regressions of DOM walking, regex boundaries and cache
 * invalidation, all of which are easier to verify end-to-end.
 *
 * @coversDefaultClass \Drupal\glossary_tooltip\Service\GlossaryParser
 * @group glossary_tooltip
 */
final class GlossaryParserTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'text',
    'field',
    'filter',
    'taxonomy',
    'glossary_tooltip',
  ];

  /**
   * Service under test.
   */
  private GlossaryParser $parser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['filter']);

    // Run the module's own install hook so the vocabulary and the
    // long-text Description field exist.
    $this->container->get('module_handler')->loadInclude('glossary_tooltip', 'install');
    glossary_tooltip_install();

    $this->parser = $this->container->get('glossary_tooltip.parser');
  }

  /**
   * Creates a glossary term and resets cached parser state.
   */
  private function createTerm(string $name, string $description = 'Sample description.'): Term {
    $term = Term::create([
      'vid' => GlossaryParser::VID,
      'name' => $name,
      'field_description' => [
        'value' => $description,
        'format' => 'plain_text',
      ],
      'status' => 1,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Ensures install hook produced the expected configuration.
   */
  public function testInstallCreatesVocabularyAndField(): void {
    $vocab = Vocabulary::load(GlossaryParser::VID);
    $this->assertNotNull($vocab, 'Glossary vocabulary exists.');
    $this->assertSame('Glossary', $vocab->label());

    $field = FieldConfig::loadByName('taxonomy_term', GlossaryParser::VID, 'field_description');
    $this->assertNotNull($field, 'field_description is attached to glossary.');
    $this->assertSame('Description', $field->getLabel());
  }

  /**
   * Empty vocabulary must not change the input.
   */
  public function testPassThroughWhenNoTerms(): void {
    $html = '<p>The quick brown fox jumps over the lazy dog.</p>';
    $this->assertSame($html, $this->parser->process($html));
  }

  /**
   * A single term is wrapped with the tooltip span.
   */
  public function testWrapsSingleMatch(): void {
    $this->createTerm('Drupal', 'A content management system.');

    $out = $this->parser->process('<p>Hello Drupal world.</p>');

    $this->assertStringContainsString('class="glossary-tooltip"', $out);
    $this->assertStringContainsString('data-description="A content management system."', $out);
    $this->assertMatchesRegularExpression('/<span[^>]*class="glossary-tooltip"[^>]*>Drupal<\/span>/', $out);
    // Surrounding text must be preserved.
    $this->assertStringContainsString('Hello ', $out);
    $this->assertStringContainsString(' world.', $out);
  }

  /**
   * Term names match case-insensitively but the original page text is kept.
   */
  public function testCaseInsensitiveMatchPreservesOriginal(): void {
    $this->createTerm('Drupal', 'A CMS.');

    $out = $this->parser->process('<p>drupal vs DRUPAL vs Drupal</p>');

    // Only the first occurrence per term is wrapped.
    preg_match_all('/<span[^>]*class="glossary-tooltip"[^>]*>([^<]+)<\/span>/', $out, $m);
    $this->assertCount(1, $m[0], 'Exactly one tooltip span emitted.');
    $this->assertSame('drupal', $m[1][0], 'Original casing of the page text is preserved.');
  }

  /**
   * Regex must respect Unicode word boundaries.
   */
  public function testRespectsWordBoundary(): void {
    $this->createTerm('Drupal', 'A CMS.');

    $out = $this->parser->process('<p>predrupalize anything</p>');

    $this->assertStringNotContainsString('glossary-tooltip', $out);
  }

  /**
   * Multi-word terms must beat their prefixes.
   */
  public function testLongestMatchWins(): void {
    $this->createTerm('Learning', 'Acquiring knowledge.');
    $this->createTerm('Machine learning', 'A field of AI.');

    $out = $this->parser->process('<p>We use machine learning daily.</p>');

    $this->assertMatchesRegularExpression(
      '/<span[^>]*class="glossary-tooltip"[^>]*>machine learning<\/span>/',
      $out,
    );
    // Plain "learning" alone must not produce an extra span.
    preg_match_all('/class="glossary-tooltip"/', $out, $m);
    $this->assertCount(1, $m[0]);
  }

  /**
   * Repeated matches of the same term emit a single span per fragment.
   */
  public function testOnlyFirstOccurrencePerTerm(): void {
    $this->createTerm('Drupal', 'A CMS.');

    $out = $this->parser->process(
      '<p>Drupal here. Drupal again. And once more: Drupal.</p>'
    );
    preg_match_all('/class="glossary-tooltip"/', $out, $m);
    $this->assertCount(1, $m[0]);
  }

  /**
   * Links, code blocks, headings and existing tooltips are skipped.
   */
  public function testSkipsExcludedRegions(): void {
    $this->createTerm('Drupal', 'A CMS.');

    $out = $this->parser->process(
      '<h2>Drupal in headings is ignored</h2>'
      . '<a href="/foo">Drupal in a link too</a>'
      . '<p><code>Drupal in code is ignored</code></p>'
      . '<p><span class="glossary-tooltip" data-description="x">Drupal already wrapped</span></p>'
      . '<p>But here Drupal must match.</p>'
    );

    // Only the trailing paragraph should produce a NEW wrapping.
    preg_match_all('/<span class="glossary-tooltip"[^>]*>Drupal<\/span>/', $out, $matches);
    $this->assertCount(1, $matches[0]);
    // Headings/links must keep their text untouched.
    $this->assertStringContainsString('<h2>Drupal in headings is ignored</h2>', $out);
    $this->assertStringContainsString('Drupal in a link too', $out);
    $this->assertStringContainsString('Drupal in code is ignored', $out);
  }

  /**
   * Descriptions over 100 chars are truncated and carry a read-more URL.
   */
  public function testLongDescriptionIsTruncated(): void {
    $long = str_repeat('word ', 40); // ~200 chars
    $this->createTerm('Drupal', $long);

    $out = $this->parser->process('<p>Drupal here</p>');

    $this->assertStringContainsString('data-read-more-url=', $out);

    preg_match('/data-description="([^"]+)"/', $out, $m);
    $this->assertNotEmpty($m, 'data-description attribute is present.');
    $this->assertLessThanOrEqual(
      GlossaryParser::TOOLTIP_LIMIT + 1,
      mb_strlen($m[1]),
      'Tooltip text honours the configured limit.',
    );
  }

  /**
   * Short descriptions do not produce a read-more URL.
   */
  public function testShortDescriptionHasNoReadMore(): void {
    $this->createTerm('Drupal', 'Short.');

    $out = $this->parser->process('<p>Drupal here</p>');

    $this->assertStringNotContainsString('data-read-more-url=', $out);
  }

  /**
   * Cached index must be refreshed after a term is updated.
   */
  public function testCacheInvalidatesOnTermChange(): void {
    $term = $this->createTerm('Drupal', 'First.');
    $first = $this->parser->process('<p>Drupal first</p>');
    $this->assertStringContainsString('data-description="First."', $first);

    // Update the term — module hook should invalidate the cache tag,
    // and the in-memory static is cleared by the hook helper as well.
    $term->set('field_description', [
      'value' => 'Second.',
      'format' => 'plain_text',
    ])->save();

    $second = $this->parser->process('<p>Drupal second</p>');
    $this->assertStringContainsString('data-description="Second."', $second);
  }

  /**
   * The parser must not break the HTML, even with malformed input.
   */
  public function testGracefulOnMalformedHtml(): void {
    $this->createTerm('Drupal', 'A CMS.');
    // Unclosed tag — Html::load() will still parse it.
    $out = $this->parser->process('<p>Drupal in messy <b>markup');
    $this->assertStringContainsString('class="glossary-tooltip"', $out);
  }

  /**
   * Empty / whitespace inputs short-circuit without touching the DOM.
   */
  public function testEmptyInputPassThrough(): void {
    $this->createTerm('Drupal', 'A CMS.');
    $this->assertSame('', $this->parser->process(''));
    $this->assertSame('   ', $this->parser->process('   '));
  }

}
