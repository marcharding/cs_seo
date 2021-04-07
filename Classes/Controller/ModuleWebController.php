<?php

namespace Clickstorm\CsSeo\Controller;

use Clickstorm\CsSeo\Service\Backend\GridService;
use Clickstorm\CsSeo\Service\EvaluationService;
use Clickstorm\CsSeo\Utility\ConfigurationUtility;
use Clickstorm\CsSeo\Utility\DatabaseUtility;
use Clickstorm\CsSeo\Utility\TSFEUtility;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Class ModuleController
 */
class ModuleWebController extends AbstractModuleController
{
    /**
     * pageRepository
     *
     * @var \TYPO3\CMS\Core\Domain\Repository\PageRepository
     */
    protected $pageRepository;

    /**
     * evaluationService
     *
     * @var EvaluationService
     */
    protected $evaluationService = null;

    /**
     * @var \TYPO3\CMS\Core\DataHandling\DataHandler
     */
    protected $dataHandler;

    /**
     * @var GridService
     */
    protected $gridService;

    /**
     * available Actions in Menu
     *
     * @var array
     */
    protected array $menuSetup = [
        'pageMeta',
        'pageIndex',
        'pageOpenGraph',
        'pageTwitterCards',
        'pageResults',
        'pageEvaluation'
    ];

    /**
     * Inject a evaluationService
     *
     * @param EvaluationService $evaluationService
     */
    public function injectEvaluationService(EvaluationService $evaluationService)
    {
        $this->evaluationService = $evaluationService;
    }

    /**
     * Inject a pageRepository
     *
     * @param \TYPO3\CMS\Core\Domain\Repository\PageRepository $pageRepository
     */
    public function injectPageRepository(PageRepository $pageRepository)
    {
        $this->pageRepository = $pageRepository;
    }

    /**
     * Show SEO fields
     */
    public function pageMetaAction()
    {
        $fieldNames = ['title', 'seo_title', 'description'];

        // get title and settings from TypoScript
        $tsfeUtility = GeneralUtility::makeInstance(TSFEUtility::class, $this->id, $this->modParams['lang']);
        $this->view->assign('previewSettings', json_encode($tsfeUtility->getPreviewSettings()));

        return $this->generateGridView($fieldNames);
    }

    protected function generateGridView(array $fieldNames, bool $showResults = false): string
    {
        $gridService = GeneralUtility::makeInstance(GridService::class);

        $gridService->setModParams($this->modParams);
        $gridService->setFieldNames($fieldNames);
        $gridService->setShowResults($showResults);

        $this->cssFiles = $gridService->getCssFiles();
        $this->jsFiles = $gridService->getJsFiles();

        $this->view->assignMultiple($gridService->processFields());

        return $this->wrapModuleTemplate();
    }

    /**
     * Show Index properties
     */
    public function pageIndexAction()
    {
        return $this->generateGridView(['title', 'canonical_link', 'no_index', 'no_follow', 'no_search']);
    }

    /**
     * Show Open Graph properties
     */
    public function pageOpenGraphAction()
    {
        return $this->generateGridView(['title', 'og_title', 'og_description', 'og_image']);
    }

    /**
     * Show Twitter Cards properties
     */
    public function pageTwitterCardsAction()
    {
        return $this->generateGridView([
            'title',
            'twitter_title',
            'twitter_description',
            'tx_csseo_tw_creator',
            'tx_csseo_tw_site',
            'twitter_image'
        ]);
    }

    /**
     * Show page evaluation results
     */
    public function pageResultsAction()
    {
        return $this->generateGridView(['title', 'tx_csseo_keyword', 'results'], true);
    }

    /**
     * Show page evaluation results
     */
    public function pageEvaluationAction()
    {
        $page = $this->pageRepository->getPage($this->modParams['id']);

        $extKey = 'cs_seo';
        $tables = [
            'pages' => LocalizationUtility::translate($GLOBALS['TCA']['pages']['ctrl']['title'], $extKey)
        ];

        $tablesToExtend = ConfigurationUtility::getTablesToExtend();

        foreach ($tablesToExtend as $tableName => $tableConfig) {
            if ($tableConfig['evaluation'] && $tableConfig['evaluation']['detailPid']) {
                $tables[$tableName] =
                    LocalizationUtility::translate($GLOBALS['TCA'][$tableName]['ctrl']['title'], $extKey);
            }
        }

        $table = $this->modParams['table'];
        if ($table && $table !== 'pages') {
            $records = DatabaseUtility::getRecords($table);
            $record = $this->modParams['record'];
            if ($record) {
                $evaluation = $this->evaluationService->getEvaluation($record, $table);
            }

            $this->view->assignMultiple(
                [
                    'record' => $record,
                    'records' => $records
                ]
            );
        } else {
            $table = 'pages';
            $languages = [];

            // get available languages
            $pageOverlays = DatabaseUtility::getPageLanguageOverlays($page['uid']);
            // get languages
            $allLanguages = DatabaseUtility::getLanguagesInBackend((int)$page['uid']);

            $languages[0] = $allLanguages[0];

            if ($pageOverlays) {
                $languagesUids = array_keys($pageOverlays);
                foreach ($allLanguages as $langUid => $languageLabel) {
                    if ($langUid > 0 && in_array($langUid, $languagesUids)) {
                        $languages[$langUid] = $languageLabel;
                    }
                }
            }

            // get page
            $languageParam = $this->modParams['lang'];
            if ($languageParam > 0) {
                $page = $this->pageRepository->getPageOverlay($page, $languageParam);
            }
            $evaluation = $this->evaluationService->getEvaluation($page);

            $langResult = $page['_PAGES_OVERLAY_LANGUAGE'] ?: 0;
            $this->view->assignMultiple(
                [
                    'lang' => $languageParam,
                    'languages' => $languages,
                    'langDisplay' => $allLanguages[$langResult]
                ]
            );
        }

        if (isset($evaluation)) {
            $results = $evaluation->getResults();
            $score = $results['Percentage'];
            unset($results['Percentage']);
            $this->view->assignMultiple(
                [
                    'evaluation' => $evaluation,
                    'score' => $score,
                    'results' => $results
                ]
            );
        }

        $emConf = ConfigurationUtility::getEmConfiguration();

        $this->view->assignMultiple(
            [
                'emConf' => $emConf,
                'page' => $page,
                'tables' => $tables,
                'table' => $table
            ]
        );

        $this->requireJsModules = [
            'TYPO3/CMS/CsSeo/Evaluation'
        ];

        $this->jsFiles = [
            'jquery.min.js',
            'select2.js'
        ];

        $this->cssFiles = [
            'Icons.css',
            'Lib/select2.css',
            'Evaluation.css'
        ];

        return $this->wrapModuleTemplate();
    }

    /**
     * Renders the menu so that it can be returned as response to an AJAX call
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function update(ServerRequestInterface $request)
    {
        // get parameter
        $postdata = file_get_contents('php://input');
        $attr = json_decode($postdata, true);

        // prepare data array
        $tableName = 'pages';
        $uid = $attr['entry']['uid'];
        $field = $attr['field'];

        // check for language overlay
        if ($attr['entry']['_PAGES_OVERLAY'] && isset($GLOBALS['TCA']['pages']['columns'][$field])) {
            $uid = $attr['entry']['_PAGES_OVERLAY_UID'];
        }

        // update map
        $data[$tableName][$uid][$field] = $attr['value'];

        // update data
        $dataHandler = $this->getDataHandler();
        $dataHandler->datamap = $data;
        $dataHandler->process_datamap();
        $response = new HtmlResponse('');
        if (!empty($dataHandler->errorLog)) {
            $response->getBody()->write('Error: ' . implode(',', $dataHandler->errorLog));
        }

        return $response;
    }
}
