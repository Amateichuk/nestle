<?php

declare(strict_types=1);

namespace Drupal\Tests\glossary_tooltip\Unit\Plugin\Filter;

use Drupal\Tests\UnitTestCase;
use Drupal\filter\FilterProcessResult;
use Drupal\glossary_tooltip\Plugin\Filter\FilterGlossaryTooltip;
use Drupal\glossary_tooltip\Service\GlossaryParser;
use Drupal\glossary_tooltip\Service\GlossaryParserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for the glossary tooltip filter plugin.
 *
 * The filter is a thin adapter around GlossaryParser, so the contract
 * we want to verify is small and easily mockable:
 *  - the wrapped HTML is whatever the parser returned,
 *  - the result advertises the glossary cache tag (so the formatted-text
 *    cache invalidates when terms change),
 *  - the tooltip library is attached for front-end rendering.
 *
 * @coversDefaultClass \Drupal\glossary_tooltip\Plugin\Filter\FilterGlossaryTooltip
 * @group glossary_tooltip
 */
final class FilterGlossaryTooltipTest extends UnitTestCase {

  /**
   * Returns a filter plugin instance with a mocked parser.
   */
  private function makeFilter(GlossaryParserInterface $parser): FilterGlossaryTooltip {
    return new FilterGlossaryTooltip(
      [],
      'filter_glossary_tooltip',
      [
        'provider' => 'glossary_tooltip',
        'id' => 'filter_glossary_tooltip',
      ],
      $parser,
    );
  }

  /**
   * The processed text is whatever the parser produced.
   */
  public function testProcessDelegatesToParser(): void {
    $parser = $this->createMock(GlossaryParserInterface::class);
    $parser->expects($this->once())
      ->method('process')
      ->with('<p>raw</p>')
      ->willReturn('<p>wrapped</p>');

    $result = $this->makeFilter($parser)->process('<p>raw</p>', 'en');

    $this->assertInstanceOf(FilterProcessResult::class, $result);
    $this->assertSame('<p>wrapped</p>', $result->getProcessedText());
  }

  /**
   * The glossary cache tag is attached to the filter result.
   */
  public function testProcessAddsGlossaryCacheTag(): void {
    $parser = $this->createMock(GlossaryParserInterface::class);
    $parser->method('process')->willReturn('<p>x</p>');

    $result = $this->makeFilter($parser)->process('<p>x</p>', 'en');

    $this->assertContains(GlossaryParser::CACHE_TAG, $result->getCacheTags());
  }

  /**
   * The tooltip front-end library is attached to the filter result.
   */
  public function testProcessAttachesLibrary(): void {
    $parser = $this->createMock(GlossaryParserInterface::class);
    $parser->method('process')->willReturn('<p>x</p>');

    $result = $this->makeFilter($parser)->process('<p>x</p>', 'en');
    $attachments = $result->getAttachments();

    $this->assertArrayHasKey('library', $attachments);
    $this->assertContains('glossary_tooltip/tooltip', $attachments['library']);
  }

  /**
   * The plugin builds correctly through the container factory.
   */
  public function testCreateUsesContainerService(): void {
    $parser = $this->createMock(GlossaryParserInterface::class);
    $container = $this->createMock(ContainerInterface::class);
    $container->expects($this->once())
      ->method('get')
      ->with('glossary_tooltip.parser')
      ->willReturn($parser);

    $instance = FilterGlossaryTooltip::create(
      $container,
      [],
      'filter_glossary_tooltip',
      ['provider' => 'glossary_tooltip', 'id' => 'filter_glossary_tooltip'],
    );

    $this->assertInstanceOf(FilterGlossaryTooltip::class, $instance);
  }

}
