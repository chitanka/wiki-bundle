<?php namespace Chitanka\WikiBundle\Controller;

use Chitanka\WikiBundle\Service\WikiEngine;
use Chitanka\WikiBundle\Service\WikiPage;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends Controller {

	public function showAction($page) {
		$page = $this->wikiEngine()->getPage($page);
		$responseForPage = function(WikiPage $page) {
			return $page->exists() ? null : new Response('', Response::HTTP_NOT_FOUND);
		};
		return $this->renderTemplate('show', [
			'page' => $page,
		], $responseForPage($page));
	}

	public function editAction($page) {
		$this->assertEditPermission();
		return $this->renderTemplate('edit', [
			'page' => $this->wikiEngine()->getPage($page, true, false),
		]);
	}

	public function saveAction($page, Request $request) {
		$this->assertEditPermission();
		$input = $request->request;
		$wiki = $this->wikiEngine();
		$user = $this->getUser();
		$wiki->savePage($input->get('summary'), $page, $input->get('content'), $input->get('title'), "{$user->getUsername()} <{$user->getUsername()}@chitanka>");
		return $this->redirectToRoute('chitanka_wiki', ['page' => $page]);
	}

	public function previewAction(Request $request) {
		return $this->renderTemplate('preview', ['content' => WikiEngine::markdownToHtml($request->request->get('content'))]);
	}

	public function historyAction($page) {
		$wiki = $this->wikiEngine();
		$commits = $wiki->getHistory($page);
		return $this->renderTemplate('history', [
			'page' => $wiki->getPage($page),
			'commits' => $commits,
		]);
	}

	public function allAction() {
		return $this->renderTemplate('all', [
			'pages' => $this->wikiEngine()->getAllPages(),
		]);
	}

	private function assertEditPermission() {
		$this->denyAccessUnlessGranted('ROLE_WIKI_EDITOR');
	}

	private function wikiEngine() {
		return new WikiEngine($this->container->getParameter('chitanka_wiki.content_dir'));
	}

	private function renderTemplate($template, $variables, $response = null) {
		return $this->render("ChitankaWikiBundle:Default:{$template}.html.twig", $variables, $response);
	}
}
