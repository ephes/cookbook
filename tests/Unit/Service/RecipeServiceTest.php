<?php

// SPDX-FileCopyrightText: 2026 Nextcloud cookbook contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Cookbook\tests\Unit\Service;

use DOMDocument;
use OCA\Cookbook\Helper\Filter\JSON\ExtractImageUrlFilter;
use OCA\Cookbook\Helper\Filter\JSON\FixImageSchemeFilter;
use OCA\Cookbook\Helper\Filter\JSON\JSONFilter;
use OCA\Cookbook\Helper\HTMLParser\HttpJsonLdParser;
use OCA\Cookbook\Helper\HTMLParser\HttpMicrodataParser;
use OCA\Cookbook\Service\HtmlDownloadService;
use OCA\Cookbook\Service\JsonService;
use OCA\Cookbook\Service\RecipeExtractionService;
use OCA\Cookbook\Service\RecipeService;
use OCP\Files\File;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * @covers \OCA\Cookbook\Service\RecipeService
 */
class RecipeServiceTest extends TestCase {
	private const CANONICAL = 'https://mobile.kptncook.com/recipe/pinterest/Pasta/372e5024';
	private const SHARE = 'https://kptncook.onelink.me/Dh4a?recipe_uid=372e5024';

	public static function recipeSourceUrls(): array {
		$jsonLd = '<script type="application/ld+json">'
			. '{"@context":"https://schema.org","@type":"Recipe","name":"Pasta",'
			. '"url":"https://example.net/original","image":"//images.kptncook.com/pasta.jpg",'
			. '"recipeInstructions":["Cook pasta."]}</script>';
		return [
			'jsonld canonical source' => [self::SHARE, '<link rel="canonical" href="' . self::CANONICAL . '">' . $jsonLd, self::CANONICAL],
			'jsonld on other websites keeps requested source' => ['https://example.com/recipe', $jsonLd, 'https://example.com/recipe'],
			'share link' => [self::SHARE, '<link rel="canonical" href="' . self::CANONICAL . '">', self::CANONICAL],
			'short link' => ['https://mobile.kptncook.com/r/372e5024', '<link rel="canonical" href="' . self::CANONICAL . '">', self::CANONICAL],
			'browser link' => ['https://mobile.kptncook.com/recipe/Pasta/372e5024', '<link rel="canonical" href="' . self::CANONICAL . '">', self::CANONICAL],
			'rel tokens and case' => [self::SHARE, '<link rel="alternate CANONICAL" href="' . self::CANONICAL . '">', self::CANONICAL],
			'missing' => [self::SHARE, '', self::SHARE],
			'empty' => [self::SHARE, '<link rel="canonical" href="">', self::SHARE],
			'unrelated rel token' => [self::SHARE, '<link rel="not-canonical" href="' . self::CANONICAL . '">', self::SHARE],
			'relative' => [self::SHARE, '<link rel="canonical" href="/recipe/pinterest/Pasta/372e5024">', self::SHARE],
			'protocol relative' => [self::SHARE, '<link rel="canonical" href="//mobile.kptncook.com/recipe/pinterest/Pasta/372e5024">', self::SHARE],
			'cross host' => [self::SHARE, '<link rel="canonical" href="https://example.com/recipe/pinterest/Pasta/372e5024">', self::SHARE],
			'host suffix' => [self::SHARE, '<link rel="canonical" href="https://mobile.kptncook.com.example.com/recipe/pinterest/Pasta/372e5024">', self::SHARE],
			'non http' => [self::SHARE, '<link rel="canonical" href="ftp://mobile.kptncook.com/recipe/pinterest/Pasta/372e5024">', self::SHARE],
			'javascript' => [self::SHARE, '<link rel="canonical" href="javascript://mobile.kptncook.com/recipe/pinterest/Pasta/372e5024">', self::SHARE],
			'credentials' => [self::SHARE, '<link rel="canonical" href="https://user:password@mobile.kptncook.com/recipe/pinterest/Pasta/372e5024">', self::SHARE],
			'port' => [self::SHARE, '<link rel="canonical" href="https://mobile.kptncook.com:8080/recipe/pinterest/Pasta/372e5024">', self::SHARE],
			'homepage' => [self::SHARE, '<link rel="canonical" href="https://mobile.kptncook.com/">', self::SHARE],
			'other website unchanged' => ['https://example.com/recipe', '<link rel="canonical" href="' . self::CANONICAL . '">', 'https://example.com/recipe'],
			'ignore invalid before valid' => [self::SHARE, '<link rel="canonical" href="https://example.com/"><link rel="canonical" href="' . self::CANONICAL . '">', self::CANONICAL],
		];
	}

	/**
	 * @dataProvider recipeSourceUrls
	 */
	public function testDownloadRecipeSourceUrl(string $requested, string $links, string $expected): void {
		$l = $this->createStub(IL10N::class);
		$document = new DOMDocument();
		$document->loadHTML('<html><head>' . $links . '</head><body>'
			. '<div itemscope itemtype="http://schema.org/Recipe">'
			. '<span itemprop="name">Pasta</span><img itemprop="image" src="//images.kptncook.com/pasta.jpg">'
			. '<div class="kptn-step-title"><div><span>1. </span>Cook pasta.</div></div>'
			. '</div></body></html>');

		$download = $this->createMock(HtmlDownloadService::class);
		$download->expects($this->once())->method('downloadRecipe')->with($requested);
		$download->method('getDom')->willReturn($document);
		$extraction = new RecipeExtractionService(
			new HttpJsonLdParser($l, new JsonService()),
			new HttpMicrodataParser($l),
			$l,
		);
		$imageExtractor = new ExtractImageUrlFilter($l, $this->createStub(LoggerInterface::class));
		$imageFilter = new FixImageSchemeFilter($l);
		$filter = $this->createMock(JSONFilter::class);
		$filter->expects($this->once())->method('apply')->willReturnCallback(function (array $json) use ($expected, $imageExtractor, $imageFilter): array {
			$this->assertSame($expected, $json['url']);
			$imageExtractor->apply($json);
			$imageFilter->apply($json);
			return $json;
		});

		$service = $this->getMockBuilder(RecipeService::class)->disableOriginalConstructor()->onlyMethods(['addRecipe'])->getMock();
		foreach (['htmlDownloadService' => $download, 'recipeExtractionService' => $extraction, 'jsonFilter' => $filter] as $property => $value) {
			(new ReflectionProperty(RecipeService::class, $property))->setValue($service, $value);
		}
		$file = $this->createStub(File::class);
		$service->expects($this->once())->method('addRecipe')->with(
			$this->callback(function (array $json) use ($expected): bool {
				$this->assertSame($expected, $json['url']);
				$this->assertSame(['Cook pasta.'], $json['recipeInstructions']);
				$this->assertSame('https://images.kptncook.com/pasta.jpg', $json['image']);
				return true;
			}),
			$document->saveHTML(),
		)->willReturn($file);

		$this->assertSame($file, $service->downloadRecipe($requested));
	}
}
