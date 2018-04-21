<?php namespace Chitanka\WikiBundle\Service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WikiEngine {

	const MD_EXTENSION = '.md';

	/**
	 * Convert a text from Markdown into HTML
	 * @param string $markdownContent
	 * @return string
	 */
	public function markdownToHtml($markdownContent, $filename) {
		$markdownContent = $this->processIncludeTags($markdownContent, $filename);
		$html = Markdown::defaultTransform($markdownContent);
		$html = preg_replace_callback('#<p>(<img [^>]+>)</p>#', function($match) {
			if (preg_match('#title="([^"]+)"#', $match[1], $submatch)) {
				return '<p class="image">'
					. $match[1] . '<span class="image-title">'.$submatch[1].'</span>'
					. '</p>';
			}
			return '<p class="image">'.$match[1].'</p>';
		}, $html);
		return $html;
	}

	/** @var string */
	private $wikiPath;
	/** @var GitRepository */
	private $repo;

	/**
	 * @param string $wikiPath
	 */
	public function __construct($wikiPath) {
		$this->wikiPath = realpath($wikiPath);
	}

	/**
	 * @param string $filename
	 * @param bool $withAncestors
	 * @param bool $withRendering
	 * @return WikiPage
	 */
	public function getPage($filename, $withAncestors = true, $withRendering = true) {
		$filename = $this->sanitizeFileName($filename);
		try {
			list($metadata, $content) = $this->getPageSections($filename);
		} catch (NotFoundHttpException $ex) {
			$metadata = '';
			$content = null;
		}
		$ancestors = $withAncestors ? $this->getAncestors($filename) : [];
		$contentAsHtml = $withRendering ? $this->markdownToHtml($content, $filename) : null;
		$page = new WikiPage($filename, $content, $contentAsHtml, $metadata, $ancestors);
		return $page;
	}

	/**
	 * @param string $filename
	 * @return WikiPage[]
	 */
	public function getAncestors($filename) {
		$ancestors = [];
		if (strpos($filename, '/') !== false) {
			$ancestorNames = explode('/', $filename);
			array_pop($ancestorNames);
			$currentAncestorName = '';
			foreach ($ancestorNames as $ancestorName) {
				$currentAncestorName .= '/'.$ancestorName;
				$ancestors[] = $this->getPage($currentAncestorName, false, false);
			}
		}
		return $ancestors;
	}

	/**
	 *
	 * @param string $editSummary
	 * @param string $filename
	 * @param string $content
	 * @param string $title
	 * @param string $author
	 */
	public function savePage($editSummary, $filename, $content, $title = null, $author = null) {
		$fullpath = $this->getFullPath($filename);
		$title = $title ? trim($title) : $filename;
		$content = trim($content) . "\n";
		$fullContent = "Title: $title\n\n$content";
		if (!file_exists($dir = dirname($fullpath))) {
			mkdir($dir, 0755, true);
		}
		file_put_contents($fullpath, $fullContent);
		$editSummary = '['.$this->sanitizeFileName($filename).'] '.$editSummary;
		$this->repo()->stageAndCommitWithAuthor($fullpath, $editSummary, $author);
	}

	/**
	 * @param string $filename
     * @return \GitElephant\Objects\Log
	 */
	public function getHistory($filename) {
		$commits = $this->repo()->getLog('master', $this->getFullPath($filename), null);
		return $commits;
	}

	public function getAllPages() {
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->wikiPath, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		$paths = [];
		foreach ($iter as $path => $dir) {
			if (!$dir->isDir() && strpos($path, '/.') === false) {
				$pageName = strtr($path, ["$this->wikiPath/" => '', self::MD_EXTENSION => '']);
				$paths[$pageName] = $this->getPage($pageName, false);
			}
		}
		ksort($paths);
		return $paths;
	}

	/**
	 * @param string $filename
	 * @return array
	 */
	protected function getPageSections($filename) {
		$fullpath = $this->getFullPath($filename);
		if (!file_exists($fullpath)) {
			throw new NotFoundHttpException("Page '$filename' does not exist.");
		}
		$sections = explode("\n\n", file_get_contents($fullpath), 2);
		if (count($sections) < 2) {
			array_unshift($sections, '');
		}
		return $sections;
	}

	protected function processIncludeTags($content, $currentPage) {
		return preg_replace_callback('#{include:(.+)}#U', function ($matches) use ($currentPage) {
			$includedPage = trim($matches[1]);
			if ($includedPage[0] === '/') {
				$includedPage = ltrim($includedPage, '/');
			} else {
				$includedPage = $this->removeExtensionFromFilename($currentPage).'/'.$includedPage;
			}
			return trim($this->getPageSections($includedPage)[1]);
		}, $content);
	}

	/**
	 * @param string $filename
	 * @return string
	 */
	protected function getFullPath($filename) {
		return $this->wikiPath .'/'. $this->sanitizeFileName($filename);
	}

	/**
	 * @param string $filename
	 * @return string
	 */
	protected function sanitizeFileName($filename) {
		$sanitizedFilename = strtr(strtolower($filename), [
			'_' => '-',
		]);
		$sanitizedFilename = preg_replace('#[^a-z\d/.-]#', '', $sanitizedFilename);
		$sanitizedFilename = trim($sanitizedFilename, '/.');
		if (strpos($sanitizedFilename, '.') === false) {
			$sanitizedFilename .= self::MD_EXTENSION;
		}
		return $sanitizedFilename;
	}

	protected function removeExtensionFromFilename($filename) {
		return str_replace(self::MD_EXTENSION, '', $filename);
	}

	/** @return GitRepository */
	protected function repo() {
		return $this->repo ?: $this->repo = new GitRepository($this->wikiPath);
	}
}
