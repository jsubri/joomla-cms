<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Debug
 *
 * @copyright   (C) 2006 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use DebugBar\DataCollector\MemoryCollector;
use DebugBar\DataCollector\MessagesCollector;
use DebugBar\DataCollector\RequestDataCollector;
use DebugBar\DebugBar;
use DebugBar\OpenHandler;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\Event\ConnectionEvent;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\System\Debug\DataCollector\InfoCollector;
use Joomla\Plugin\System\Debug\DataCollector\LanguageErrorsCollector;
use Joomla\Plugin\System\Debug\DataCollector\LanguageFilesCollector;
use Joomla\Plugin\System\Debug\DataCollector\LanguageStringsCollector;
use Joomla\Plugin\System\Debug\DataCollector\ProfileCollector;
use Joomla\Plugin\System\Debug\DataCollector\QueryCollector;
use Joomla\Plugin\System\Debug\DataCollector\SessionCollector;
use Joomla\Plugin\System\Debug\JavascriptRenderer;
use Joomla\Plugin\System\Debug\Storage\FileStorage;

/**
 * Joomla! Debug plugin.
 *
 * @since  1.5
 */
class PlgSystemDebug extends CMSPlugin
{
	/**
	 * True if debug lang is on.
	 *
	 * @var    boolean
	 * @since  3.0
	 */
	private $debugLang = false;

	/**
	 * Holds log entries handled by the plugin.
	 *
	 * @var    LogEntry[]
	 * @since  3.1
	 */
	private $logEntries = [];

	/**
	 * Holds SHOW PROFILES of queries.
	 *
	 * @var    array
	 * @since  3.1.2
	 */
	private $sqlShowProfiles = [];

	/**
	 * Holds all SHOW PROFILE FOR QUERY n, indexed by n-1.
	 *
	 * @var    array
	 * @since  3.1.2
	 */
	private $sqlShowProfileEach = [];

	/**
	 * Holds all EXPLAIN EXTENDED for all queries.
	 *
	 * @var    array
	 * @since  3.1.2
	 */
	private $explains = [];

	/**
	 * Holds total amount of executed queries.
	 *
	 * @var    int
	 * @since  3.2
	 */
	private $totalQueries = 0;

	/**
	 * Application object.
	 *
	 * @var    CMSApplicationInterface
	 * @since  3.3
	 */
	protected $app;

	/**
	 * Database object.
	 *
	 * @var    DatabaseDriver
	 * @since  3.8.0
	 */
	protected $db;

	/**
	 * @var DebugBar
	 * @since 4.0.0
	 */
	private $debugBar;

	/**
	 * The query monitor.
	 *
	 * @var    \Joomla\Database\Monitor\DebugMonitor
	 * @since  4.0.0
	 */
	private $queryMonitor;

	/**
	 * AJAX marker
	 *
	 * @var   bool
	 * @since 4.0.0
	 */
	protected $isAjax = false;

	/**
	 * Whether displaing a logs is enabled
	 *
	 * @var   bool
	 * @since 4.0.0
	 */
	protected $showLogs = false;

	/**
	 * Constructor.
	 *
	 * @param   DispatcherInterface  &$subject  The object to observe.
	 * @param   array                $config    An optional associative array of configuration settings.
	 *
	 * @since   1.5
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		$this->debugLang = $this->app->get('debug_lang');

		// Skip the plugin if debug is off
		if (!$this->debugLang && !$this->app->get('debug'))
		{
			return;
		}

		$this->app->getConfig()->set('gzip', false);
		ob_start();
		ob_implicit_flush(false);

		/** @var \Joomla\Database\Monitor\DebugMonitor */
		$this->queryMonitor = $this->db->getMonitor();

		if (!$this->params->get('queries', 1))
		{
			// Remove the database driver monitor
			$this->db->setMonitor(null);
		}

		$storagePath = JPATH_CACHE . '/plg_system_debug_' . $this->app->getName();

		$this->debugBar = new DebugBar;
		$this->debugBar->setStorage(new FileStorage($storagePath));

		$this->isAjax = $this->app->input->get('option') === 'com_ajax'
			&& $this->app->input->get('plugin') === 'debug' && $this->app->input->get('group') === 'system';

		$this->showLogs = (bool) $this->params->get('logs', false);
	}

	/**
	 * Add the CSS for debug.
	 * We can't do this in the constructor because stuff breaks.
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	public function onAfterDispatch()
	{
		// Only if debugging or language debug is enabled.
		if ((JDEBUG || $this->debugLang) && $this->isAuthorisedDisplayDebug() && $this->app->getDocument() instanceof HtmlDocument)
		{
			// Use our own jQuery and fontawesome instead of the debug bar shipped version
			$assetManager = $this->app->getDocument()->getWebAssetManager();
			$assetManager->registerAndUseStyle(
				'plg.system.debug',
				'plg_system_debug/debug.css',
				[],
				[],
				['fontawesome']
			);
			$assetManager->registerAndUseScript(
				'plg.system.debug',
				'plg_system_debug/debug.min.js',
				[],
				['defer' => true],
				['jquery']
			);
		}

		// Disable asset media version if needed.
		if (JDEBUG && (int) $this->params->get('refresh_assets', 1) === 0)
		{
			$this->app->getDocument()->setMediaVersion(null);
		}

		// Log deprecated class aliases
		if ($this->showLogs && $this->app->get('log_deprecated'))
		{
			foreach (JLoader::getDeprecatedAliases() as $deprecation)
			{
				Log::add(
					sprintf(
						'%1$s has been aliased to %2$s and the former class name is deprecated. The alias will be removed in %3$s.',
						$deprecation['old'],
						$deprecation['new'],
						$deprecation['version']
					),
					Log::WARNING,
					'deprecation-notes'
				);
			}
		}
	}

	/**
	 * Show the debug info.
	 *
	 * @return  void
	 *
	 * @since   1.6
	 */
	public function onAfterRespond()
	{
		// Do not render if debugging or language debug is not enabled.
		if (!JDEBUG && !$this->debugLang || $this->isAjax || !($this->app->getDocument() instanceof HtmlDocument))
		{
			return;
		}

		// User has to be authorised to see the debug information.
		if (!$this->isAuthorisedDisplayDebug())
		{
			return;
		}

		// Load language.
		$this->loadLanguage();

		$this->debugBar->addCollector(new InfoCollector($this->params, $this->debugBar->getCurrentRequestId()));

		if (JDEBUG)
		{
			if ($this->params->get('memory', 1))
			{
				$this->debugBar->addCollector(new MemoryCollector);
			}

			if ($this->params->get('request', 1))
			{
				$this->debugBar->addCollector(new RequestDataCollector);
			}

			if ($this->params->get('session', 1))
			{
				$this->debugBar->addCollector(new SessionCollector($this->params));
			}

			if ($this->params->get('profile', 1))
			{
				$this->debugBar->addCollector(new ProfileCollector($this->params));
			}

			if ($this->params->get('queries', 1))
			{
				// Call $db->disconnect() here to trigger the onAfterDisconnect() method here in this class!
				$this->db->disconnect();
				$this->debugBar->addCollector(new QueryCollector($this->params, $this->queryMonitor, $this->sqlShowProfileEach, $this->explains));
			}

			if ($this->showLogs)
			{
				$this->collectLogs();
			}
		}

		if ($this->debugLang)
		{
			$this->debugBar->addCollector(new LanguageFilesCollector($this->params));
			$this->debugBar->addCollector(new LanguageStringsCollector($this->params));
			$this->debugBar->addCollector(new LanguageErrorsCollector($this->params));
		}

		// Only render for HTML output.
		if (!($this->app->getDocument() instanceof HtmlDocument))
		{
			$this->debugBar->stackData();

			return;
		}

		$debugBarRenderer = new JavascriptRenderer($this->debugBar, Uri::root(true) . '/media/vendor/debugbar/');
		$openHandlerUrl   = Uri::base(true) . '/index.php?option=com_ajax&plugin=debug&group=system&format=raw&action=openhandler';
		$openHandlerUrl  .= '&' . Session::getFormToken() . '=1';

		$debugBarRenderer->setOpenHandlerUrl($openHandlerUrl);

		/**
		 * @todo disable highlightjs from the DebugBar, import it through NPM
		 *       and deliver it through Joomla's API
		 *       Also every DebugBar script and stylesheet needs to use Joomla's API
		 *       $debugBarRenderer->disableVendor('highlightjs');
		 */

		// Capture output.
		$contents = ob_get_contents();

		if ($contents)
		{
			ob_end_clean();
		}

		// No debug for Safari and Chrome redirection.
		if (strpos($contents, '<html><head><meta http-equiv="refresh" content="0;') === 0
			&& strpos(strtolower($_SERVER['HTTP_USER_AGENT'] ?? ''), 'webkit') !== false)
		{
			$this->debugBar->stackData();

			echo $contents;

			return;
		}

		echo str_replace('</body>', $debugBarRenderer->renderHead() . $debugBarRenderer->render() . '</body>', $contents);
	}

	/**
	 * AJAX handler
	 *
	 * @return  string
	 *
	 * @since  4.0.0
	 */
	public function onAjaxDebug()
	{
		// Do not render if debugging or language debug is not enabled.
		if (!JDEBUG && !$this->debugLang)
		{
			return '';
		}

		// User has to be authorised to see the debug information.
		if (!$this->isAuthorisedDisplayDebug() || !Session::checkToken('request'))
		{
			return '';
		}

		switch ($this->app->input->get('action'))
		{
			case 'openhandler':
				$handler = new OpenHandler($this->debugBar);

				return $handler->handle($this->app->input->request->getArray(), false, false);
			default:
				return '';
		}
	}

	/**
	 * Method to check if the current user is allowed to see the debug information or not.
	 *
	 * @return  boolean  True if access is allowed.
	 *
	 * @since   3.0
	 */
	private function isAuthorisedDisplayDebug(): bool
	{
		static $result = null;

		if ($result !== null)
		{
			return $result;
		}

		// If the user is not allowed to view the output then end here.
		$filterGroups = (array) $this->params->get('filter_groups', []);

		if (!empty($filterGroups))
		{
			$userGroups = $this->app->getIdentity()->get('groups');

			if (!array_intersect($filterGroups, $userGroups))
			{
				$result = false;

				return false;
			}
		}

		$result = true;

		return true;
	}

	/**
	 * Disconnect handler for database to collect profiling and explain information.
	 *
	 * @param   ConnectionEvent  $event  Event object
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
	public function onAfterDisconnect(ConnectionEvent $event)
	{
		if (!JDEBUG)
		{
			return;
		}

		$db = $event->getDriver();

		// Remove the monitor to avoid monitoring the following queries
		$db->setMonitor(null);

		$this->totalQueries = $db->getCount();

		if ($this->params->get('query_profiles') && $db->getServerType() === 'mysql')
		{
			try
			{
				// Check if profiling is enabled.
				$db->setQuery("SHOW VARIABLES LIKE 'have_profiling'");
				$hasProfiling = $db->loadResult();

				if ($hasProfiling)
				{
					// Run a SHOW PROFILE query.
					$db->setQuery('SHOW PROFILES');
					$this->sqlShowProfiles = $db->loadAssocList();

					if ($this->sqlShowProfiles)
					{
						foreach ($this->sqlShowProfiles as $qn)
						{
							// Run SHOW PROFILE FOR QUERY for each query where a profile is available (max 100).
							$db->setQuery('SHOW PROFILE FOR QUERY ' . (int) $qn['Query_ID']);
							$this->sqlShowProfileEach[(int) ($qn['Query_ID'] - 1)] = $db->loadAssocList();
						}
					}
				}
				else
				{
					$this->sqlShowProfileEach[0] = [['Error' => 'MySql have_profiling = off']];
				}
			}
			catch (Exception $e)
			{
				$this->sqlShowProfileEach[0] = [['Error' => $e->getMessage()]];
			}
		}

		if ($this->params->get('query_explains') && in_array($db->getServerType(), ['mysql', 'postgresql'], true))
		{
			$logs        = $this->queryMonitor->getLogs();
			$boundParams = $this->queryMonitor->getBoundParams();

			foreach ($logs as $k => $query)
			{
				$dbVersion56 = $db->getServerType() === 'mysql' && version_compare($db->getVersion(), '5.6', '>=');
				$dbVersion80 = $db->getServerType() === 'mysql' && version_compare($db->getVersion(), '8.0', '>=');

				if ($dbVersion80)
				{
					$dbVersion56 = false;
				}

				if ((stripos($query, 'select') === 0) || ($dbVersion56 && ((stripos($query, 'delete') === 0) || (stripos($query, 'update') === 0))))
				{
					try
					{
						$queryInstance = $db->getQuery(true);
						$queryInstance->setQuery('EXPLAIN ' . ($dbVersion56 ? 'EXTENDED ' : '') . $query);

						if ($boundParams[$k])
						{
							foreach ($boundParams[$k] as $key => $obj)
							{
								$queryInstance->bind($key, $obj->value, $obj->dataType, $obj->length, $obj->driverOptions);
							}
						}

						$this->explains[$k] = $db->setQuery($queryInstance)->loadAssocList();
					}
					catch (Exception $e)
					{
						$this->explains[$k] = [['error' => $e->getMessage()]];
					}
				}
			}
		}
	}

	/**
	 * Store log messages so they can be displayed later.
	 * This function is passed log entries by JLogLoggerCallback.
	 *
	 * @param   LogEntry  $entry  A log entry.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 *
	 * @deprecated  5.0  Use Log::add(LogEntry $entry);
	 */
	public function logger(LogEntry $entry)
	{
		if (!$this->showLogs)
		{
			return;
		}

		$this->logEntries[] = $entry;
	}

	/**
	 * Collect log messages.
	 *
	 * @return $this
	 *
	 * @since 4.0.0
	 */
	private function collectLogs(): self
	{
		$loggerOptions = ['group' => 'default'];
		$logger        = new Joomla\CMS\Log\Logger\InMemoryLogger($loggerOptions);
		$logEntries    = $logger->getCollectedEntries();

		if (!$this->logEntries && !$logEntries)
		{
			return $this;
		}

		if ($this->logEntries)
		{
			$logEntries = array_merge($logEntries, $this->logEntries);
		}

		$logDeprecated = $this->app->get('log_deprecated', 0);
		$logDeprecatedCore = $this->params->get('log-deprecated-core', 0);

		$this->debugBar->addCollector(new MessagesCollector('log'));

		if ($logDeprecated)
		{
			$this->debugBar->addCollector(new MessagesCollector('deprecated'));
			$this->debugBar->addCollector(new MessagesCollector('deprecation-notes'));
		}

		if ($logDeprecatedCore)
		{
			$this->debugBar->addCollector(new MessagesCollector('deprecated-core'));
		}

		foreach ($logEntries as $entry)
		{
			switch ($entry->category)
			{
				case 'deprecation-notes':
					if ($logDeprecated)
					{
						$this->debugBar[$entry->category]->addMessage($entry->message);
					}
				break;
				case 'deprecated':
					if (!$logDeprecated && !$logDeprecatedCore)
					{
						break;
					}

<<<<<<< HEAD
					$barWidth = $minWidth;
				}

				$bars[$id] = (object) array(
					'class' => $barClass,
					'width' => $barWidth,
					'pre'   => $barPre,
					'tip'   => sprintf('%.2f ms', $queryTime),
				);
				$info[$id] = (object) array(
					'class'       => $labelClass,
					'explain'     => $explain,
					'profile'     => $profile,
					'hasWarnings' => $hasWarnings,
				);
			}
		}

		// Remove single queries from $duplicates.
		$total_duplicates = 0;

		foreach ($duplicates as $did => $dups)
		{
			if (count($dups) < 2)
			{
				unset($duplicates[$did]);
			}
			else
			{
				$total_duplicates += count($dups);
			}
		}

		// Fix first bar width.
		$minWidth = 0.3;

		if ($bars[0]->width < $minWidth && isset($bars[1]))
		{
			$bars[1]->pre -= ($minWidth - $bars[0]->width);

			if ($bars[1]->pre < 0)
			{
				$minWidth     += $bars[1]->pre;
				$bars[1]->pre = 0;
			}

			$bars[0]->width = $minWidth;
		}

		$memoryUsageNow = memory_get_usage();
		$list           = array();

		foreach ($log as $id => $query)
		{
			// Start query type ticker additions.
			$fromStart  = stripos($query, 'from');
			$whereStart = stripos($query, 'where', $fromStart);

			if ($whereStart === false)
			{
				$whereStart = stripos($query, 'order by', $fromStart);
			}

			if ($whereStart === false)
			{
				$whereStart = strlen($query) - 1;
			}

			$fromString = substr($query, 0, $whereStart);
			$fromString = str_replace(array("\t", "\n"), ' ', $fromString);
			$fromString = trim($fromString);

			// Initialise the select/other query type counts the first time.
			if (!isset($selectQueryTypeTicker[$fromString]))
			{
				$selectQueryTypeTicker[$fromString] = 0;
			}

			if (!isset($otherQueryTypeTicker[$fromString]))
			{
				$otherQueryTypeTicker[$fromString] = 0;
			}

			// Increment the count.
			if (stripos($query, 'select') === 0)
			{
				$selectQueryTypeTicker[$fromString]++;
				unset($otherQueryTypeTicker[$fromString]);
			}
			else
			{
				$otherQueryTypeTicker[$fromString]++;
				unset($selectQueryTypeTicker[$fromString]);
			}

			$text = $this->highlightQuery($query);

			if ($timings && isset($timings[$id * 2 + 1]))
			{
				// Compute the query time.
				$queryTime = ($timings[$id * 2 + 1] - $timings[$id * 2]) * 1000;

				// Timing
				// Formats the output for the query time with EXPLAIN query results as tooltip:
				$htmlTiming = '<div style="margin: 0 0 5px;"><span class="dbg-query-time">';
				$htmlTiming .= JText::sprintf(
					'PLG_DEBUG_QUERY_TIME',
					sprintf(
						'<span class="label %s">%.2f&nbsp;ms</span>',
						$info[$id]->class,
						$timing[$id]['0']
					)
				);

				if ($timing[$id]['1'])
				{
					$htmlTiming .= ' ' . JText::sprintf(
							'PLG_DEBUG_QUERY_AFTER_LAST',
							sprintf('<span class="label label-default">%.2f&nbsp;ms</span>', $timing[$id]['1'])
						);
				}

				$htmlTiming .= '</span>';

				if (isset($callStacks[$id][0]['memory']))
				{
					$memoryUsed        = $callStacks[$id][0]['memory'][1] - $callStacks[$id][0]['memory'][0];
					$memoryBeforeQuery = $callStacks[$id][0]['memory'][0];
=======
					$file = $entry->callStack[2]['file'] ?? '';
					$line = $entry->callStack[2]['line'] ?? '';
>>>>>>> 5ddc984651db52d74ccaac71ab714ffbe101234c

					if (!$file)
					{
						// In case trigger_error is used
						$file = $entry->callStack[4]['file'] ?? '';
						$line = $entry->callStack[4]['line'] ?? '';
					}

					$category = $entry->category;
					$relative = str_replace(JPATH_ROOT, '', $file);

					if (0 === strpos($relative, '/libraries/src'))
					{
						if (!$logDeprecatedCore)
						{
							break;
						}

						$category .= '-core';
					}
					elseif (!$logDeprecated)
					{
						break;
					}

<<<<<<< HEAD
					$htmlQuery = '<div class="alert alert-error">' . JText::_('PLG_DEBUG_QUERY_DUPLICATES') . ': ' . implode('&nbsp; ', $dups) . '</div>'
						. '<pre class="alert" title="' . htmlspecialchars(JText::_('PLG_DEBUG_QUERY_DUPLICATES_FOUND'), ENT_COMPAT, 'UTF-8') . '">' . $text . '</pre>';
				}
				else
				{
					$htmlQuery = '<pre>' . $text . '</pre>';
				}

				$list[] = '<a name="dbg-query-' . ($id + 1) . '"></a>'
					. $htmlTiming
					. $htmlBar
					. $htmlQuery
					. $htmlAccordions;
			}
			else
			{
				$list[] = '<pre>' . $text . '</pre>';
			}
		}

		$totalTime = 0;

		foreach (JProfiler::getInstance('Application')->getMarks() as $mark)
		{
			$totalTime += $mark->time;
		}

		if ($totalQueryTime > ($totalTime * 0.25))
		{
			$labelClass = 'label-important';
		}
		elseif ($totalQueryTime < ($totalTime * 0.15))
		{
			$labelClass = 'label-success';
		}
		else
		{
			$labelClass = 'label-warning';
		}

		if ($this->totalQueries === 0)
		{
			$this->totalQueries = $db->getCount();
		}

		$html = array();

		$html[] = '<h4>' . JText::sprintf('PLG_DEBUG_QUERIES_LOGGED', $this->totalQueries)
			. sprintf(' <span class="label ' . $labelClass . '">%.2f&nbsp;ms</span>', $totalQueryTime) . '</h4><br />';

		if ($total_duplicates)
		{
			$html[] = '<div class="alert alert-error">'
				. '<h4>' . JText::sprintf('PLG_DEBUG_QUERY_DUPLICATES_TOTAL_NUMBER', $total_duplicates) . '</h4>';

			foreach ($duplicates as $dups)
			{
				$links = array();

				foreach ($dups as $dup)
				{
					$links[] = '<a class="alert-link" href="#dbg-query-' . ($dup + 1) . '">#' . ($dup + 1) . '</a>';
				}

				$html[] = '<div>' . JText::sprintf('PLG_DEBUG_QUERY_DUPLICATES_NUMBER', count($links)) . ': ' . implode('&nbsp; ', $links) . '</div>';
			}

			$html[] = '</div>';
		}

		$html[] = '<ol><li>' . implode('<hr /></li><li>', $list) . '<hr /></li></ol>';

		if (!$this->params->get('query_types', 1))
		{
			return implode('', $html);
		}

		// Get the totals for the query types.
		$totalSelectQueryTypes = count($selectQueryTypeTicker);
		$totalOtherQueryTypes  = count($otherQueryTypeTicker);
		$totalQueryTypes       = $totalSelectQueryTypes + $totalOtherQueryTypes;

		$html[] = '<h4>' . JText::sprintf('PLG_DEBUG_QUERY_TYPES_LOGGED', $totalQueryTypes) . '</h4>';
=======
					$message = [
						'message' => $entry->message,
						'caller' => $file . ':' . $line,
						// @todo 'stack' => $entry->callStack;
					];
					$this->debugBar[$category]->addMessage($message, 'warning');
				break;
>>>>>>> 5ddc984651db52d74ccaac71ab714ffbe101234c

				case 'databasequery':
					// Should be collected by its own collector
				break;

				default:
					switch ($entry->priority)
					{
						case Log::EMERGENCY:
						case Log::ALERT:
						case Log::CRITICAL:
						case Log::ERROR:
							$level = 'error';
							break;
						case Log::WARNING:
							$level = 'warning';
							break;
						default:
							$level = 'info';
					}

					$this->debugBar['log']->addMessage($entry->category . ' - ' . $entry->message, $level);
					break;
			}
		}

<<<<<<< HEAD
		return implode('', $html);
	}

	/**
	 * Render the bars.
	 *
	 * @param   array    &$bars  Array of bar data
	 * @param   string   $class  Optional class for items
	 * @param   integer  $id     Id if the bar to highlight
	 *
	 * @return  string
	 *
	 * @since   3.1.2
	 */
	protected function renderBars(&$bars, $class = '', $id = null)
	{
		$html = array();

		foreach ($bars as $i => $bar)
		{
			if (isset($bar->pre) && $bar->pre)
			{
				$html[] = '<div class="dbg-bar-spacer" style="width:' . $bar->pre . '%;"></div>';
			}

			$barClass = trim('bar dbg-bar progress-bar ' . (isset($bar->class) ? $bar->class : ''));

			if ($id !== null && $i == $id)
			{
				$barClass .= ' dbg-bar-active';
			}

			$tip = empty($bar->tip) ? '' : ' title="' . htmlspecialchars($bar->tip, ENT_COMPAT, 'UTF-8') . '"';

			$html[] = '<a class="bar dbg-bar ' . $barClass . '"' . $tip . ' style="width: '
				. $bar->width . '%;" href="#dbg-' . $class . '-' . ($i + 1) . '"></a>';
		}

		return '<div class="progress dbg-bars dbg-bars-' . $class . '">' . implode('', $html) . '</div>';
	}

	/**
	 * Render an HTML table based on a multi-dimensional array.
	 *
	 * @param   array    $table         An array of tabular data.
	 * @param   boolean  &$hasWarnings  Changes value to true if warnings are displayed, otherwise untouched
	 *
	 * @return  string
	 *
	 * @since   3.1.2
	 */
	protected function tableToHtml($table, &$hasWarnings)
	{
		if (!$table)
		{
			return null;
		}

		$html = array();

		$html[] = '<table class="table table-striped dbg-query-table">';
		$html[] = '<thead>';
		$html[] = '<tr>';

		foreach (array_keys($table[0]) as $k)
		{
			$html[] = '<th>' . htmlspecialchars($k) . '</th>';
		}

		$html[]    = '</tr>';
		$html[]    = '</thead>';
		$html[]    = '<tbody>';
		$durations = array();

		foreach ($table as $tr)
		{
			if (isset($tr['Duration']))
			{
				$durations[] = $tr['Duration'];
			}
		}

		rsort($durations, SORT_NUMERIC);

		foreach ($table as $tr)
		{
			$html[] = '<tr>';

			foreach ($tr as $k => $td)
			{
				if ($td === null)
				{
					// Display null's as 'NULL'.
					$td = 'NULL';
				}

				// Treat special columns.
				if ($k === 'Duration')
				{
					if ($td >= 0.001 && ($td == $durations[0] || (isset($durations[1]) && $td == $durations[1])))
					{
						// Duration column with duration value of more than 1 ms and within 2 top duration in SQL engine: Highlight warning.
						$html[]      = '<td class="dbg-warning">';
						$hasWarnings = true;
					}
					else
					{
						$html[] = '<td>';
					}

					// Display duration in milliseconds with the unit instead of seconds.
					$html[] = sprintf('%.2f&nbsp;ms', $td * 1000);
				}
				elseif ($k === 'Error')
				{
					// An error in the EXPLAIN query occurred, display it instead of the result (means original query had syntax error most probably).
					$html[]      = '<td class="dbg-warning">' . htmlspecialchars($td);
					$hasWarnings = true;
				}
				elseif ($k === 'key')
				{
					if ($td === 'NULL')
					{
						// Displays query parts which don't use a key with warning:
						$html[]      = '<td><strong>' . '<span class="dbg-warning" title="'
							. htmlspecialchars(JText::_('PLG_DEBUG_WARNING_NO_INDEX_DESC'), ENT_COMPAT, 'UTF-8') . '">'
							. JText::_('PLG_DEBUG_WARNING_NO_INDEX') . '</span>' . '</strong>';
						$hasWarnings = true;
					}
					else
					{
						$html[] = '<td><strong>' . htmlspecialchars($td) . '</strong>';
					}
				}
				elseif ($k === 'Extra')
				{
					$htmlTd = htmlspecialchars($td);

					// Replace spaces with &nbsp; (non-breaking spaces) for less tall tables displayed.
					$htmlTd = preg_replace('/([^;]) /', '\1&nbsp;', $htmlTd);

					// Displays warnings for "Using filesort":
					$htmlTdWithWarnings = str_replace(
						'Using&nbsp;filesort',
						'<span class="dbg-warning" title="'
						. htmlspecialchars(JText::_('PLG_DEBUG_WARNING_USING_FILESORT_DESC'), ENT_COMPAT, 'UTF-8') . '">'
						. JText::_('PLG_DEBUG_WARNING_USING_FILESORT') . '</span>',
						$htmlTd
					);

					if ($htmlTdWithWarnings !== $htmlTd)
					{
						$hasWarnings = true;
					}

					$html[] = '<td>' . $htmlTdWithWarnings;
				}
				else
				{
					$html[] = '<td>' . htmlspecialchars($td);
				}

				$html[] = '</td>';
			}

			$html[] = '</tr>';
		}

		$html[] = '</tbody>';
		$html[] = '</table>';

		return implode('', $html);
	}

	/**
	 * Disconnect handler for database to collect profiling and explain information.
	 *
	 * @param   JDatabaseDriver  &$db  Database object.
	 *
	 * @return  void
	 *
	 * @since   3.1.2
	 */
	public function mysqlDisconnectHandler(&$db)
	{
		$db->setDebug(false);

		$this->totalQueries = $db->getCount();

		$dbVersion5037 = $db->getServerType() === 'mysql' && version_compare($db->getVersion(), '5.0.37', '>=');

		if ($dbVersion5037)
		{
			try
			{
				// Check if profiling is enabled.
				$db->setQuery("SHOW VARIABLES LIKE 'have_profiling'");
				$hasProfiling = $db->loadResult();

				if ($hasProfiling)
				{
					// Run a SHOW PROFILE query.
					$db->setQuery('SHOW PROFILES');
					$this->sqlShowProfiles = $db->loadAssocList();

					if ($this->sqlShowProfiles)
					{
						foreach ($this->sqlShowProfiles as $qn)
						{
							// Run SHOW PROFILE FOR QUERY for each query where a profile is available (max 100).
							$db->setQuery('SHOW PROFILE FOR QUERY ' . (int) $qn['Query_ID']);
							$this->sqlShowProfileEach[(int) ($qn['Query_ID'] - 1)] = $db->loadAssocList();
						}
					}
				}
				else
				{
					$this->sqlShowProfileEach[0] = array(array('Error' => 'MySql have_profiling = off'));
				}
			}
			catch (Exception $e)
			{
				$this->sqlShowProfileEach[0] = array(array('Error' => $e->getMessage()));
			}
		}

		if (in_array($db->getServerType(), array('mysql', 'postgresql'), true))
		{
			$log = $db->getLog();

			foreach ($log as $k => $query)
			{
				$dbVersion56 = $db->getServerType() === 'mysql' && version_compare($db->getVersion(), '5.6', '>=');
				$dbVersion80 = $db->getServerType() === 'mysql' && version_compare($db->getVersion(), '8.0', '>=');

				if ($dbVersion80)
				{
					$dbVersion56 = false;
				}

				if ((stripos($query, 'select') === 0) || ($dbVersion56 && ((stripos($query, 'delete') === 0) || (stripos($query, 'update') === 0))))
				{
					try
					{
						$db->setQuery('EXPLAIN ' . ($dbVersion56 ? 'EXTENDED ' : '') . $query);
						$this->explains[$k] = $db->loadAssocList();
					}
					catch (Exception $e)
					{
						$this->explains[$k] = array(array('Error' => $e->getMessage()));
					}
				}
			}
		}
	}

	/**
	 * Displays errors in language files.
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function displayLanguageFilesInError()
	{
		$errorfiles = JFactory::getLanguage()->getErrorFiles();

		if (!count($errorfiles))
		{
			return '<p>' . JText::_('JNONE') . '</p>';
		}

		$html = array();

		$html[] = '<ul>';

		foreach ($errorfiles as $file => $error)
		{
			$html[] = '<li>' . $this->formatLink($file) . str_replace($file, '', $error) . '</li>';
		}

		$html[] = '</ul>';

		return implode('', $html);
	}

	/**
	 * Display loaded language files.
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function displayLanguageFilesLoaded()
	{
		$html = array();

		$html[] = '<ul>';

		foreach (JFactory::getLanguage()->getPaths() as /* $extension => */ $files)
		{
			foreach ($files as $file => $status)
			{
				$html[] = '<li>';

				$html[] = $status
					? JText::_('PLG_DEBUG_LANG_LOADED')
					: JText::_('PLG_DEBUG_LANG_NOT_LOADED');

				$html[] = ' : ';
				$html[] = $this->formatLink($file);
				$html[] = '</li>';
			}
		}

		$html[] = '</ul>';

		return implode('', $html);
	}

	/**
	 * Display untranslated language strings.
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function displayUntranslatedStrings()
	{
		$stripFirst = $this->params->get('strip-first', 1);
		$stripPref  = $this->params->get('strip-prefix');
		$stripSuff  = $this->params->get('strip-suffix');

		$orphans = JFactory::getLanguage()->getOrphans();

		if (!count($orphans))
		{
			return '<p>' . JText::_('JNONE') . '</p>';
		}

		ksort($orphans, SORT_STRING);

		$guesses = array();

		foreach ($orphans as $key => $occurance)
		{
			if (is_array($occurance) && isset($occurance[0]))
			{
				$info = $occurance[0];
				$file = $info['file'] ?: '';

				if (!isset($guesses[$file]))
				{
					$guesses[$file] = array();
				}

				// Prepare the key.
				if (($pos = strpos($info['string'], '=')) > 0)
				{
					$parts = explode('=', $info['string']);
					$key   = $parts[0];
					$guess = $parts[1];
				}
				else
				{
					$guess = str_replace('_', ' ', $info['string']);

					if ($stripFirst)
					{
						$parts = explode(' ', $guess);

						if (count($parts) > 1)
						{
							array_shift($parts);
							$guess = implode(' ', $parts);
						}
					}

					$guess = trim($guess);

					if ($stripPref)
					{
						$guess = trim(preg_replace(chr(1) . '^' . $stripPref . chr(1) . 'i', '', $guess));
					}

					if ($stripSuff)
					{
						$guess = trim(preg_replace(chr(1) . $stripSuff . '$' . chr(1) . 'i', '', $guess));
					}
				}

				$key = strtoupper(trim($key));
				$key = preg_replace('#\s+#', '_', $key);
				$key = preg_replace('#\W#', '', $key);

				// Prepare the text.
				$guesses[$file][] = $key . '="' . $guess . '"';
			}
		}

		$html = array();

		foreach ($guesses as $file => $keys)
		{
			$html[] = "\n\n# " . ($file ? $this->formatLink($file) : JText::_('PLG_DEBUG_UNKNOWN_FILE')) . "\n\n";
			$html[] = implode("\n", $keys);
		}

		return '<pre>' . implode('', $html) . '</pre>';
	}

	/**
	 * Simple highlight for SQL queries.
	 *
	 * @param   string  $query  The query to highlight.
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function highlightQuery($query)
	{
		$newlineKeywords = '#\b(FROM|LEFT|INNER|OUTER|WHERE|SET|VALUES|ORDER|GROUP|HAVING|LIMIT|ON|AND|CASE)\b#i';

		$query = htmlspecialchars($query, ENT_QUOTES);

		$query = preg_replace($newlineKeywords, '<br />&#160;&#160;\\0', $query);

		$regex = array(

			// Tables are identified by the prefix.
			'/(=)/'                                        => '<b class="dbg-operator">$1</b>',

			// All uppercase words have a special meaning.
			'/(?<!\w|>)([A-Z_]{2,})(?!\w)/x'               => '<span class="dbg-command">$1</span>',

			// Tables are identified by the prefix.
			'/(' . $this->db->getPrefix() . '[a-z_0-9]+)/' => '<span class="dbg-table">$1</span>',

		);

		$query = preg_replace(array_keys($regex), array_values($regex), $query);

		$query = str_replace('*', '<b style="color: red;">*</b>', $query);

		return $query;
	}

	/**
	 * Render the backtrace.
	 *
	 * Stolen from JError to prevent it's removal.
	 *
	 * @param   Exception  $error  The Exception object to be rendered.
	 *
	 * @return  string     Rendered backtrace.
	 *
	 * @since   2.5
	 */
	protected function renderBacktrace($error)
	{
		return JLayoutHelper::render('joomla.error.backtrace', array('backtrace' => $error->getTrace()));
	}

	/**
	 * Replaces the Joomla! root with "JROOT" to improve readability.
	 * Formats a link with a special value xdebug.file_link_format
	 * from the php.ini file.
	 *
	 * @param   string  $file  The full path to the file.
	 * @param   string  $line  The line number.
	 *
	 * @return  string
	 *
	 * @since   2.5
	 */
	protected function formatLink($file, $line = '')
	{
		return JHtml::_('debug.xdebuglink', $file, $line);
	}

	/**
	 * Store log messages so they can be displayed later.
	 * This function is passed log entries by JLogLoggerCallback.
	 *
	 * @param   JLogEntry  $entry  A log entry.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function logger(JLogEntry $entry)
	{
		$this->logEntries[] = $entry;
	}

	/**
	 * Display log messages.
	 *
	 * @return  string
	 *
	 * @since   3.1
	 */
	protected function displayLogs()
	{
		$priorities = array(
			JLog::EMERGENCY => '<span class="badge badge-important">EMERGENCY</span>',
			JLog::ALERT     => '<span class="badge badge-important">ALERT</span>',
			JLog::CRITICAL  => '<span class="badge badge-important">CRITICAL</span>',
			JLog::ERROR     => '<span class="badge badge-important">ERROR</span>',
			JLog::WARNING   => '<span class="badge badge-warning">WARNING</span>',
			JLog::NOTICE    => '<span class="badge badge-info">NOTICE</span>',
			JLog::INFO      => '<span class="badge badge-info">INFO</span>',
			JLog::DEBUG     => '<span class="badge">DEBUG</span>',
		);

		$out = '';

		$logEntriesTotal = count($this->logEntries);

		// SQL log entries
		$showExecutedSQL = $this->params->get('log-executed-sql', 0);

		if (!$showExecutedSQL)
		{
			$logEntriesDatabasequery = count(
				array_filter(
					$this->logEntries, function ($logEntry)
					{
						return $logEntry->category === 'databasequery';
					}
				)
			);
			$logEntriesTotal         -= $logEntriesDatabasequery;
		}

		// Deprecated log entries
		$logEntriesDeprecated = count(
			array_filter(
				$this->logEntries, function ($logEntry)
				{
					return $logEntry->category === 'deprecated';
				}
			)
		);
		$showDeprecated       = $this->params->get('log-deprecated', 0);

		if (!$showDeprecated)
		{
			$logEntriesTotal -= $logEntriesDeprecated;
		}

		$showEverything = $this->params->get('log-everything', 0);

		$out .= '<h4>' . JText::sprintf('PLG_DEBUG_LOGS_LOGGED', $logEntriesTotal) . '</h4><br />';

		if ($showDeprecated && $logEntriesDeprecated > 0)
		{
			$out .= '
			<div class="alert alert-warning">
				<h4>' . JText::sprintf('PLG_DEBUG_LOGS_DEPRECATED_FOUND_TITLE', $logEntriesDeprecated) . '</h4>
				<div>' . JText::_('PLG_DEBUG_LOGS_DEPRECATED_FOUND_TEXT') . '</div>
			</div>
			<br />';
		}

		$out   .= '<ol>';
		$count = 1;

		foreach ($this->logEntries as $entry)
		{
			// Don't show database queries if not selected.
			if (!$showExecutedSQL && $entry->category === 'databasequery')
			{
				continue;
			}

			// Don't show deprecated logs if not selected.
			if (!$showDeprecated && $entry->category === 'deprecated')
			{
				continue;
			}

			// Don't show everything logs if not selected.
			if (!$showEverything && !in_array($entry->category, array('deprecated', 'databasequery'), true))
			{
				continue;
			}

			$out .= '<li id="dbg_logs_' . $count . '">';
			$out .= '<h5>' . $priorities[$entry->priority] . ' ' . $entry->category . '</h5><br />
				<pre>' . $entry->message . '</pre>';

			if ($entry->callStack)
			{
				$out .= JHtml::_('bootstrap.startAccordion', 'dbg_logs_' . $count, array('active' => ''));
				$out .= JHtml::_('bootstrap.addSlide', 'dbg_logs_' . $count, JText::_('PLG_DEBUG_CALL_STACK'), 'dbg_logs_backtrace_' . $count);
				$out .= $this->renderCallStack($entry->callStack);
				$out .= JHtml::_('bootstrap.endSlide');
				$out .= JHtml::_('bootstrap.endAccordion');
			}

			$out .= '<hr /></li>';
			$count++;
		}

		$out .= '</ol>';

		return $out;
	}

	/**
	 * Renders call stack and back trace in HTML.
	 *
	 * @param   array  $callStack  The call stack and back trace array.
	 *
	 * @return  string  The call stack and back trace in HMTL format.
	 *
	 * @since   3.5
	 */
	protected function renderCallStack(array $callStack = array())
	{
		$htmlCallStack = '';

		if ($callStack !== null)
		{
			$htmlCallStack .= '<div>';
			$htmlCallStack .= '<table class="table table-striped dbg-query-table">';
			$htmlCallStack .= '<thead>';
			$htmlCallStack .= '<tr>';
			$htmlCallStack .= '<th>#</th>';
			$htmlCallStack .= '<th>' . JText::_('PLG_DEBUG_CALL_STACK_CALLER') . '</th>';
			$htmlCallStack .= '<th>' . JText::_('PLG_DEBUG_CALL_STACK_FILE_AND_LINE') . '</th>';
			$htmlCallStack .= '</tr>';
			$htmlCallStack .= '</thead>';
			$htmlCallStack .= '<tbody>';

			$count = count($callStack);

			foreach ($callStack as $call)
			{
				// Dont' back trace log classes.
				if (isset($call['class']) && strpos($call['class'], 'JLog') !== false)
				{
					$count--;
					continue;
				}

				$htmlCallStack .= '<tr>';

				$htmlCallStack .= '<td>' . $count . '</td>';

				$htmlCallStack .= '<td>';

				if (isset($call['class']))
				{
					// If entry has Class/Method print it.
					$htmlCallStack .= htmlspecialchars($call['class'] . $call['type'] . $call['function']) . '()';
				}
				else
				{
					if (isset($call['args']))
					{
						// If entry has args is a require/include.
						$htmlCallStack .= htmlspecialchars($call['function']) . ' ' . $this->formatLink($call['args'][0]);
					}
					else
					{
						// It's a function.
						$htmlCallStack .= htmlspecialchars($call['function']) . '()';
					}
				}

				$htmlCallStack .= '</td>';

				$htmlCallStack .= '<td>';

				// If entry doesn't have line and number the next is a call_user_func.
				if (!isset($call['file']) && !isset($call['line']))
				{
					$htmlCallStack .= JText::_('PLG_DEBUG_CALL_STACK_SAME_FILE');
				}
				// If entry has file and line print it.
				else
				{
					$htmlCallStack .= $this->formatLink(htmlspecialchars($call['file']), htmlspecialchars($call['line']));
				}

				$htmlCallStack .= '</td>';

				$htmlCallStack .= '</tr>';
				$count--;
			}

			$htmlCallStack .= '</tbody>';
			$htmlCallStack .= '</table>';
			$htmlCallStack .= '</div>';

			if (!$this->linkFormat)
			{
				$htmlCallStack .= '<div>[<a href="https://xdebug.org/docs/all_settings#file_link_format" target="_blank" rel="noopener noreferrer">';
				$htmlCallStack .= JText::_('PLG_DEBUG_LINK_FORMAT') . '</a>]</div>';
			}
		}

		return $htmlCallStack;
	}

	/**
	 * Pretty print JSON with colors.
	 *
	 * @param   string  $json  The json raw string.
	 *
	 * @return  string  The json string pretty printed.
	 *
	 * @since   3.5
	 */
	protected function prettyPrintJSON($json = '')
	{
		// In PHP 5.4.0 or later we have pretty print option.
		if (version_compare(PHP_VERSION, '5.4', '>='))
		{
			$json = json_encode($json, JSON_PRETTY_PRINT);
		}

		// Add some colors
		$json = preg_replace('#"([^"]+)":#', '<span class=\'black\'>"</span><span class=\'green\'>$1</span><span class=\'black\'>"</span>:', $json);
		$json = preg_replace('#"(|[^"]+)"(\n|\r\n|,)#', '<span class=\'grey\'>"$1"</span>$2', $json);
		$json = str_replace('null,', '<span class=\'blue\'>null</span>,', $json);

		return $json;
	}

	/**
	 * Write query to the log file
	 *
	 * @return  void
	 *
	 * @since   3.5
	 */
	protected function writeToFile()
	{
		$app    = JFactory::getApplication();
		$domain = $app->isClient('site') ? 'site' : 'admin';
		$input  = $app->input;
		$file   = $app->get('log_path') . '/' . $domain . '_' . $input->get('option') . $input->get('view') . $input->get('layout') . '.sql.php';

		// Get the queries from log.
		$current = '';
		$db      = $this->db;
		$log     = $db->getLog();
		$timings = $db->getTimings();

		foreach ($log as $id => $query)
		{
			if (isset($timings[$id * 2 + 1]))
			{
				$temp    = str_replace('`', '', $log[$id]);
				$temp    = str_replace(array("\t", "\n", "\r\n"), ' ', $temp);
				$current .= $temp . ";\n";
			}
		}

		if (JFile::exists($file))
		{
			JFile::delete($file);
		}

		$head   = array('#');
		$head[] = '#<?php die(\'Forbidden.\'); ?>';
		$head[] = '#Date: ' . gmdate('Y-m-d H:i:s') . ' UTC';
		$head[] = '#Software: ' . \JPlatform::getLongVersion();
		$head[] = "\n";

		// Write new file.
		JFile::write($file, implode("\n", $head) . $current);
=======
		return $this;
>>>>>>> 5ddc984651db52d74ccaac71ab714ffbe101234c
	}
}
