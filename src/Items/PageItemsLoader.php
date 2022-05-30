<?php

declare(strict_types=1);

namespace Hofff\Contao\Navigation\Items;

use Contao\FrontendUser;
use Contao\ModuleModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Hofff\Contao\Navigation\QueryBuilder\PageQueryBuilder;
use Symfony\Component\Security\Core\Security;

use function array_flip;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_shift;
use function implode;
use function max;
use function sprintf;

use const PHP_INT_MAX;

final class PageItemsLoader
{
    private Connection $connection;

    private Security $security;

    private PageQueryBuilder $pageQueryBuilder;

    public function __construct(Connection $connection, Security $security, PageQueryBuilder $pageQueryBuilder)
    {
        $this->connection       = $connection;
        $this->security         = $security;
        $this->pageQueryBuilder = $pageQueryBuilder;
    }

    public function load(
        ModuleModel $moduleModel,
        array $rootIds,
        array $fields,
        $arrStop = PHP_INT_MAX,
        $intHard = PHP_INT_MAX
    ): PageItems {
        $items = new PageItems();
        $items->trail = array_flip(isset($GLOBALS['objPage']) ? $GLOBALS['objPage']->trail : []);
        if (! $rootIds) {
            return $items;
        }

        $rootIds = array_keys(array_flip($rootIds));
        $arrStop = (array) $arrStop;

        foreach ($rootIds as $rootId) {
            $items->items[$rootId] = $items->items[$rootId] ?? [];
        }

        if (! $moduleModel->hofff_navigation_includeStart) {
            $items->roots = $this->fetchItems($moduleModel, $items, $fields, $rootIds, $arrStop, $intHard);

            return $items;
        }

        $items->roots = $this->fetchItems($moduleModel, $items, $fields, $rootIds, $arrStop, $intHard, 2);

        $result = $this->connection->executeQuery(
            sprintf(
                'SELECT %s FROM tl_page WHERE id IN (:rootIds)',
                $this->getQuotedFieldsPart($fields)
            ),
            ['rootIds' => $rootIds],
            ['rootIds' => Connection::PARAM_STR_ARRAY]
        );

        while ($row = $result->fetchAssociative()) {
            $items->items[$row['id']] = $row;
            $items->roots[$row['id']] = true;
        }

        return $items;
    }

    /**
     * Fetches page data for all navigation items below the given roots.
     *
     * @param integer $arrPIDs  The root pages of the navigation.
     * @param integer $intStop  (optional, defaults to PHP_INT_MAX) The soft limit of depth.
     * @param integer $intHard  (optional, defaults to PHP_INT_MAX) The hard limit of depth.
     * @param integer $intLevel (optional, defaults to 1) The level of the PIDs.
     *
     * @return array<int,bool>
     */
    protected function fetchItems(
        ModuleModel $moduleModel,
        PageItems $items,
        array $fields,
        array $arrPIDs,
        $arrStop = PHP_INT_MAX,
        $intHard = PHP_INT_MAX,
        $intLevel = 1
    ): array {
        $intLevel = max(1, $intLevel);
        $arrStop  = (array) $arrStop;

        // nothing todo
        // $intLevel == $intHard + 1 requires subitem detection for css class "submenu" calculation
        if (! $arrPIDs || $intLevel - 1 > $intHard) {
            return [];
        }

        $queryBuilder = $this->connection->createQueryBuilder()
            ->from('tl_page')
            ->select(... $fields)
            ->andWhere('type != :rootType')
            ->setParameter('rootType', 'root')
            ->andWhere('pid IN (:pids)')
            ->orderBy('sorting');

        $this->pageQueryBuilder
            ->addHiddenCondition(
                $queryBuilder,
                (bool) $moduleModel->hofff_navigation_showHidden,
                (bool) $moduleModel->hofff_navigation_isSitemap
            )
            ->addPublishedCondition($queryBuilder)
            ->addErrorPagesCondition($queryBuilder, (bool) $moduleModel->hofff_navigation_showErrorPages)
            ->addGuestsQueryParts($queryBuilder, (bool) $moduleModel->backboneit_navigation_showGuests);

        $arrFetched = [];

        while ($arrPIDs) {
            // if $arrEndPIDs == $arrPIDs the next $arrPIDs will be empty -> leave loop
            if ($intLevel > $intHard) {
                $arrEndPIDs = $arrPIDs;
            } elseif ($intLevel > $arrStop[0]) {
                count($arrStop) > 1 && array_shift($arrStop);
                $arrEndPIDs = [];
                foreach ($arrPIDs as $intPID) {
                    if ($items->isInTrail((int) $intPID)) {
                        $arrEndPIDs[$intPID] = true;
                    }
                }
            } else {
                $arrEndPIDs = [];
            }

            $query = clone $queryBuilder;
            $query->setParameter('pids', $arrPIDs, Connection::PARAM_INT_ARRAY);

            $result = $query->execute();
            if ($result->rowCount() === 0) {
                break;
            }

            $arrPIDs = [];
            while ($arrPage = $result->fetchAssociative()) {
                if (isset($items->items[$arrPage['id']])) {
                    continue;
                }

                if (! $this->isPermissionGranted($moduleModel, $arrPage)) {
                    continue;
                }

                if (! isset($arrEndPIDs[$arrPage['pid']])) {
                    $items->subItems[(int) $arrPage['pid']][] = (int) $arrPage['id']; // for order of items
                    $items->items[(int) $arrPage['id']]       = $arrPage; // item datasets
                    $arrPIDs[]                                = $arrPage['id']; // ids of current layer (for next layer pids)
                    $arrFetched[$arrPage['id']]               = true; // fetched in this method

                } elseif (! isset($items->subItems[$arrPage['pid']])) {
                    $items->subItems[$arrPage['pid']] = [];
                }
            }

            $intLevel++;
        }

        return $arrFetched;
    }


    protected function getQuotedFieldsPart(array $fields): string
    {
        return implode(', ', array_map([$this->connection, 'quoteIdentifier'], $fields));
    }

    /**
     * Utility method.
     *
     * THIS IS NOT THE OPPOSITE OF ::isPermissionDenied()!
     *
     * Checks if the current user has permission to view the page of the given
     * page dataset, in regards to the current navigation settings and the
     * permission requirements of the page.
     *
     * Context property: hofff_navigation_showProtected
     *
     * @param array $arrPage The page dataset of the current page, with at least
     *                       groups and protected attributes set.
     *
     * @return boolean If the permission is granted true, otherwise false.
     */
    public function isPermissionGranted(ModuleModel $model, array $arrPage): bool
    {
        // TODO: Replace with non deprecated check
        // be users have access everywhere
        if (BE_USER_LOGGED_IN) {
            return true;
        }

        // protection is ignored
        if ($model->hofff_navigation_showProtected) {
            return true;
        }

        return ! $this->isPermissionDenied($arrPage);
    }

    /**
     * Utility method.
     *
     * THIS IS NOT THE OPPOSITE OF ::isPermissionGranted()!
     *
     * Checks if the current user has no permission to view the page of the
     * given page dataset, in regards to the permission requirements of the
     * page.
     *
     * @param array $arrPage The page dataset of the current page, with at least
     *                       groups and protected attributes set.
     *
     * @return boolean If the permission is denied true, otherwise false.
     */
    public function isPermissionDenied(array $arrPage): bool
    {
        // this page is not protected
        if (! $arrPage['protected']) {
            return false;
        }

        $user = $this->security->getUser();
        if (! $user instanceof FrontendUser) {
            return true;
        }

        // the current user is not in any group
        if (! $user->groups) {
            return true;
        }

        // check if the current user is not in any group, which is allowed to access the current page
        return ! array_intersect((array)  $user->groups, StringUtil::deserialize($arrPage['groups'], true));
    }
}