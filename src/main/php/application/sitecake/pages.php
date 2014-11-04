<?php
namespace Sitecake;

use \phpQuery as phpQuery;

class pages {
	
	static function get($full = false) {
		$pages = array();
		$pageFiles = pages::page_files();
	
		$homeHtml = io::file_get_contents($pageFiles['index.html']);
		$homeDoc = phpQuery::newDocument($homeHtml);
		$nav = pages::nav_urls($homeDoc);
		$nav_urls = array_keys($nav);

		foreach ($pageFiles as $rpath => $path) {
			$url = pages::rpath2url($rpath);
			$home = ($url == 'index.html');
			$html = $home ? $homeHtml : io::file_get_contents($path);
			$doc = $home ? $homeDoc : phpQuery::newDocument($html);
			$idx = array_search($url, $nav_urls);
			$navtitle = ($idx === FALSE) ? phpQuery::pq('meta[name="navtitle"]', 
				$doc)->attr('content') : $nav[$url];			
			$navtitle = empty($navtitle) ? pages::url2navtitle($url) : 
				$navtitle;
			$page = array(
				'id' => pages::page_id($html),
				'idx' => ($idx === FALSE) ? -1 : $idx,
				'title' => phpQuery::pq('title', $doc)->text(),
				'navtitle' => $navtitle,
				'desc' => phpQuery::pq(
					'meta[name="description"]', $doc)->attr('content'),
				'keywords' => phpQuery::pq(
					'meta[name="keywords"]', $doc)->attr('content'),
				'home' => $home,
				'url' => $url
			);
			if ($full) {
				$page['path'] = pages::rpath2path($rpath);
				$page['html'] = $html;
				$page['doc'] = $doc;
			}
			array_push($pages, $page);			
		}
		return array('status' => 0, 'pages' => $pages);
	}
	
	static function update($newPages) {
		$pages = pages::get(true);
		pages::sanity_check($pages, $newPages);
		$pages = $pages['pages'];		
		$navPages = pages::nav_pages($newPages);
		pages::update_pages($pages, $newPages, $navPages);		
		pages::save_pages($newPages);		
		pages::remove_deleted_pages($pages, $newPages);
		pages::sitemap($navPages);
		return array('status' => 0, 'pages' => pages::reduce_pages($newPages));
	}
	
	static function sanity_check($pages, $newPages) {
		$homePages = util::array_filter_prop($newPages, 'home', true);
		if (!(is_array($homePages) && count($homePages) == 1))
			throw new \Exception(
				'One and only one page should be marked as the home page');
		
		$homePage = util::array_find_prop($newPages, 'home', true);
		if (!(isset($homePage['url']) && $homePage['url'] == 'index.html'))
			throw new \Exception(
				'The URL of the home page has to be index.html');
		
		array_walk($newPages, function($page) {
			if (!util::str_endswith('.html', $page['url']))
				throw new \Exception('The page URL has to end with .html');
		});
	}
	
	static function update_pages($pages, &$newPages, $navPages) {
		array_walk($newPages, 
			function(&$newPage) use($pages, $newPages, $navPages) {
				$nav = pages::gen_nav($navPages, $newPage['url']);
				if (isset($newPage['id'])) {
					pages::update_page($newPage, util::array_find_prop($pages, 
						'id', $newPage['id']), $nav);
				} else {
					pages::create_page($newPage, util::array_find_prop($pages, 
						'id', $newPage['tid']), $nav);
				}
		});				
	}

	static function save_pages($newPages) {
		array_walk($newPages, function($page) {
			io::file_put_contents($page['path'], $page['html']);		
		});
	}
	
	static function remove_deleted_pages($oldPages, $newPages) {
		
		array_walk(util::array_diff(util::array_map_prop($oldPages, 'id'),
			util::array_map_prop($newPages, 'id')), function($id) {
					draft::delete($id);
		});
		
		array_walk(util::array_diff(util::array_map_prop($oldPages, 'path'), 
			util::array_map_prop($newPages, 'path')), function($path) {
				io::unlink($path);
		});
	}
	
	static function update_page(&$newPage, $page, $nav) {		
		$newPage['url'] = $newPage['home'] ? 
			'index.html' : pages::page_url_slug($newPage['url']); 
		$newPage['path'] = pages::rpath2path(pages::url2rpath($newPage['url']));
		$newPage['doc'] = $page['doc'];
		phpQuery::pq('ul.sc-nav', $newPage['doc'])->html($nav);
		phpQuery::pq('title', $newPage['doc'])->html($newPage['title']);
		phpQuery::pq('meta[name="navtitle"]', $newPage['doc'])->remove();
		if ($newPage['idx'] == -1) {
			phpQuery::pq('title', $newPage['doc'])->after(
				'<meta name="navtitle" content="'.$newPage['navtitle'].'"/>');
		}
		phpQuery::pq('meta[name="description"]', $newPage['doc'])->remove();
		if ($newPage['desc']) {
			phpQuery::pq('title', $newPage['doc'])->
				after('<meta name="description" content="'.
					$newPage['desc'].'"/>');
		}
		phpQuery::pq('meta[name="keywords"]', $newPage['doc'])->remove();
		if ($newPage['keywords']) {
			phpQuery::pq('title', $newPage['doc'])->
				after('<meta name="keywords" content="'.
					$newPage['keywords'].'"/>');
		}
		$newPage['html'] = (string)($newPage['doc']);						
	}
	
	static function create_page(&$newPage, $page, $nav) {
		$newPage['id'] = util::id();
		$newPage['url'] = $newPage['home'] ? 
			'index.html' : pages::page_url_slug($newPage['url']);
		$newPage['path'] = pages::rpath2path(pages::url2rpath($newPage['url']));
		$newPage['doc'] = phpQuery::newDocument(preg_replace(
			'/scpageid[\s\n]*=[\s\n]*["\'][^"\']+["\']/', 'scpageid="' . 
			$newPage['id'] . '"', $page['html']));
		phpQuery::pq('ul.sc-nav', $newPage['doc'])->html($nav);
		phpQuery::pq('title', $newPage['doc'])->html($newPage['title']);
		phpQuery::pq('meta[name="navtitle"]', $newPage['doc'])->remove();
		if ($newPage['idx'] == -1) {
			phpQuery::pq('title', $newPage['doc'])->after(
				'<meta name="navtitle" content="'.$newPage['navtitle'].'"/>');
		}
		phpQuery::pq('meta[name="description"]', $newPage['doc'])->remove();
		if ($newPage['desc']) {
			phpQuery::pq('title', $newPage['doc'])->
				after('<meta name="description" content="'.
					$newPage['desc'].'"/>');
		}
		phpQuery::pq('meta[name="keywords"]', $newPage['doc'])->remove();
		if ($newPage['keywords']) {
			phpQuery::pq('title', $newPage['doc'])->
				after('<meta name="keywords" content="'.
					$newPage['keywords'].'"/>');
		}
		$newPage['html'] = (string)($newPage['doc']);
	}
	
	static function reduce_pages($pages) {
		return array_map(function($page) {
				unset($page['html']);
				unset($page['doc']);
				unset($page['path']);
				return $page;
			}, $pages);
	}
	
	/**
	 * Returns a list of page urls present in the navigation menu.
	 * 
	 * @param phpQueryObject $doc a page document
	 * @return array of page urls
	 */
	static function nav_urls($doc) {
		$pages = array();
		foreach (phpQuery::pq('ul.sc-nav:first li a', $doc) as $node) {
			$pages[$node->getAttribute('href')] = $node->textContent;
		}
		return $pages;
	}
	
	static function nav_pages($pages) {
		$navItems = array_filter($pages, function($page) {
			return ($page['idx'] >= 0);
		});
		usort($navItems, function($a, $b) {
			return ($a['idx'] - $b['idx']);
		});
		return $navItems;		
	}
	
	static function gen_nav($pages, $url) {		
		$html = '';
		foreach ($pages as $page) {
			$html .= '<li' . ($page['url'] == $url ? ' class="active">' : '>') .
				'<a href="' . $page['url'] . '">' . $page['navtitle'] . 
				'</a></li>';
		}
		return $html;
	}
	
	/**
	 * Saves the generated sitemap file.
	 *
	 * @param array $pages an array of navigable pages
	 */
	static function sitemap($pages) {
		io::file_put_contents(SITE_MAP_FILE, 
			pages::gen_sitemap($pages));
	}

	/**
	 * Generate the appropriate sitemap xml for a given array of navigable
	 * pages.
	 *
	 * @param array $pages an array of navigable pages
	 * @return string the sitemap xml
	 */
	static function gen_sitemap($pages) {
		$base = http::baseUrl();
		$result = '<?xml version="1.0" encoding="UTF-8"?>' .
			'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		foreach ($pages as $page) {
			if ($page['idx'] >= 0 || $page['url'] == 'index.html') {
				$result .= '<url><loc>' . $base . 
					($page['url'] == 'index.html' ? '' : $page['url']) . 
					'</loc></url>';
			}
		}
		return $result . '</urlset>';		
	}

	/**
	 * Returns paths of all existing page files.
	 * Page files are those files in <code>SC_ROOT</code> directory with 
	 * <code>html</code> extension.
	 * 
	 * @return array containing page files where the key is the file's relative
	 * 	path from <code>SC_ROOT</code>
	 */
	static function page_files() {
		$files = io::glob(SC_ROOT.'/*.html');
		return ($files === FALSE || empty($files)) ? array() : 
			array_combine(array_map("basename", $files), $files);
	}
	
	/**
	 * Converts the page relative path (from <code>SC_ROOT</code>) to the page
	 * relative URL.
	 * 
	 * @param string $path page file's relative path
	 * @return string relative URL
	 */
	static function rpath2url($path) {
		return str_replace("\\", "/", $path);
	}
	
	/**
	 * Converts the page relative URL to the page relative path (from 
	 * <code>SC_ROOT</code>).
	 * 
	 * @param string $url a page's relative URL
	 * @return string the page file's relative path
	 */
	static function url2rpath($url) {
		return $url;
	}
	
	/**
	 * Converts a relative page file path to the respective absolute one.
	 *
	 * @param string $rpath a relative path (to <code>SC_ROOT</code>)
	 * @return string the absolute path
	 */
	static function rpath2path($rpath) {
		return SC_ROOT.'/'.$rpath;	
	}

	/**
	 * Converts an absolute page file path to the respective relative one.
	 *
	 * @param string $path a page file absolute path
	 * @return string the relative pate (from <code>SC_ROOT</code>)
	 */
	static function path2rpath($path) {
		return (strpos($path, SC_ROOT) == 0) ? 
			substr($path.'/', strlen(SC_ROOT)) : $path;
	}
	
	/**
	 * Returns the page ID.
	 * 
	 * @param string $html the page html
	 * @return string the page ID or FALSE if not found
	 */
	static function page_id($html) {
		return (preg_match('/\s+scpageid[\s\n]*=[\s\n]*["\']([^"]+)["\']/s', 
			$html, $matches)) ? $matches[1] : FALSE;
	}
	
	static function page_url_slug($url) {
		return util::slug(substr_replace($url, '', -strlen('.html'))).'.html';
	}
	
	static function url2navtitle($url) {
		$nt = preg_replace('/-/', '', 
			substr_replace($url, '', -strlen('.html')));
		$nt[0] = strtoupper($nt[0]);
		return $nt;
	}
}