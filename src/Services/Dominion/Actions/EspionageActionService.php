<?php

namespace OpenDominion\Services\Dominion\Actions;

use DB;
use Exception;
use LogicException;
use OpenDominion\Calculators\Dominion\ImprovementCalculator;
use OpenDominion\Calculators\Dominion\LandCalculator;
use OpenDominion\Calculators\Dominion\MilitaryCalculator;
use OpenDominion\Calculators\Dominion\ProductionCalculator;
use OpenDominion\Calculators\Dominion\RangeCalculator;
use OpenDominion\Calculators\Dominion\SpellCalculator;
use OpenDominion\Exceptions\GameException;
use OpenDominion\Helpers\BuildingHelper;
use OpenDominion\Helpers\EspionageHelper;
use OpenDominion\Helpers\ImprovementHelper;
use OpenDominion\Helpers\LandHelper;
use OpenDominion\Helpers\OpsHelper;
use OpenDominion\Mappers\Dominion\InfoMapper;
use OpenDominion\Models\Dominion;
use OpenDominion\Models\InfoOp;
use OpenDominion\Services\Dominion\GovernmentService;
use OpenDominion\Services\Dominion\HistoryService;
use OpenDominion\Services\Dominion\ProtectionService;
use OpenDominion\Services\Dominion\QueueService;
use OpenDominion\Services\NotificationService;
use OpenDominion\Traits\DominionGuardsTrait;

class EspionageActionService
{
    use DominionGuardsTrait;

    /** @var BuildingHelper */
    protected $buildingHelper;

    /** @var EspionageHelper */
    protected $espionageHelper;

    /** @var GovernmentService */
    protected $governmentService;

    /** @var ImprovementCalculator */
    protected $improvementCalculator;

    /** @var ImprovementHelper */
    protected $improvementHelper;

    /** @var InfoMapper */
    protected $infoMapper;

    /** @var LandCalculator */
    protected $landCalculator;

    /** @var LandHelper */
    protected $landHelper;

    /** @var MilitaryCalculator */
    protected $militaryCalculator;

    /** @var NotificationService */
    protected $notificationService;

    /** @var OpsHelper */
    protected $opsHelper;

    /** @var ProductionCalculator */
    protected $productionCalculator;

    /** @var ProtectionService */
    protected $protectionService;

    /** @var QueueService */
    protected $queueService;

    /** @var RangeCalculator */
    protected $rangeCalculator;

    /** @var SpellCalculator */
    protected $spellCalculator;

    /**
     * EspionageActionService constructor.
     */
    public function __construct()
    {
        $this->buildingHelper = app(BuildingHelper::class);
        $this->espionageHelper = app(EspionageHelper::class);
        $this->governmentService = app(GovernmentService::class);
        $this->improvementCalculator = app(ImprovementCalculator::class);
        $this->improvementHelper = app(ImprovementHelper::class);
        $this->infoMapper = app(InfoMapper::class);
        $this->landCalculator = app(LandCalculator::class);
        $this->landHelper = app(LandHelper::class);
        $this->militaryCalculator = app(MilitaryCalculator::class);
        $this->notificationService = app(NotificationService::class);
        $this->opsHelper = app(OpsHelper::class);
        $this->productionCalculator = app(ProductionCalculator::class);
        $this->protectionService = app(ProtectionService::class);
        $this->queueService = app(QueueService::class);
        $this->rangeCalculator = app(RangeCalculator::class);
        $this->spellCalculator = app(SpellCalculator::class);
    }

    public const BLACK_OPS_HOURS_AFTER_ROUND_START = 24 * 7;
    public const THEFT_HOURS_AFTER_ROUND_START = 24 * 7;

    /**
     * Performs a espionage operation for $dominion, aimed at $target dominion.
     *
     * @param Dominion $dominion
     * @param string $operationKey
     * @param Dominion $target
     * @return array
     * @throws GameException
     * @throws LogicException
     */
    public function performOperation(Dominion $dominion, string $operationKey, Dominion $target): array
    {
        $this->guardLockedDominion($dominion);
        $this->guardLockedDominion($target);

        $operationInfo = $this->espionageHelper->getOperationInfo($operationKey);

        if (!$operationInfo) {
            throw new LogicException("Cannot perform unknown operation '{$operationKey}'");
        }

        if ($dominion->spy_strength < 30) {
            throw new GameException("Your spies do not have enough strength to perform {$operationInfo['name']}.");
        }

        if ($this->protectionService->isUnderProtection($dominion)) {
            throw new GameException('You cannot perform espionage operations while under protection');
        }

        if ($this->protectionService->isUnderProtection($target)) {
            throw new GameException('You cannot perform espionage operations on targets which are under protection');
        }

        if (!$this->rangeCalculator->isInRange($dominion, $target) && !in_array($target->id, $this->militaryCalculator->getRecentlyInvadedBy($dominion, 12))) {
            throw new GameException('You cannot perform espionage operations on targets outside of your range');
        }

        if ($this->espionageHelper->isResourceTheftOperation($operationKey)) {
            if (now()->diffInHours($dominion->round->start_date) < self::THEFT_HOURS_AFTER_ROUND_START) {
                throw new GameException('You cannot perform resource theft for the first seven days of the round');
            }
            if ($this->rangeCalculator->getDominionRange($dominion, $target) < 100) {
                throw new GameException('You cannot perform resource theft on targets smaller than yourself');
            }
        } elseif ($this->espionageHelper->isHostileOperation($operationKey)) {
            if (now()->diffInHours($dominion->round->start_date) < self::BLACK_OPS_HOURS_AFTER_ROUND_START) {
                throw new GameException('You cannot perform black ops for the first seven days of the round');
            }
        }

        if ($dominion->round->id !== $target->round->id) {
            throw new GameException('Nice try, but you cannot perform espionage operations cross-round');
        }

        if ($dominion->realm->id === $target->realm->id) {
            throw new GameException('Nice try, but you cannot perform espionage oprations on your realmies');
        }

        $result = null;

        DB::transaction(function () use ($dominion, $target, $operationKey, &$result) {
            if ($this->espionageHelper->isInfoGatheringOperation($operationKey)) {
                if ($dominion->pack !== null && $dominion->pack->size > 2) {
                    $spyStrengthLost = 2;
                } else {
                    $spyStrengthLost = 1.5;
                }
                $result = $this->performInfoGatheringOperation($dominion, $operationKey, $target);

            } elseif ($this->espionageHelper->isResourceTheftOperation($operationKey)) {
                $spyStrengthLost = 5;
                $result = $this->performResourceTheftOperation($dominion, $operationKey, $target);

            } elseif ($this->espionageHelper->isHostileOperation($operationKey)) {
                $spyStrengthLost = 5;
                $result = $this->performHostileOperation($dominion, $operationKey, $target);

            } else {
                throw new LogicException("Unknown type for espionage operation {$operationKey}");
            }

            $dominion->spy_strength -= $spyStrengthLost;

            if ($result['success']) {
                $dominion->stat_espionage_success += 1;
            } else {
                $dominion->stat_espionage_failure += 1;
            }

            $dominion->save([
                'event' => HistoryService::EVENT_ACTION_PERFORM_ESPIONAGE_OPERATION,
                'action' => $operationKey,
                'target_dominion_id' => $target->id
            ]);

            if ($dominion->fresh()->spy_strength < 25) {
                throw new GameException('Your spies have run out of strength');
            }

            $target->save([
                'event' => HistoryService::EVENT_ACTION_PERFORM_ESPIONAGE_OPERATION,
                'action' => $operationKey,
                'source_dominion_id' => $dominion->id
            ]);
        });

        $this->rangeCalculator->checkGuardApplications($dominion, $target);

        return [
                'message' => $result['message'],
                'data' => [
                    'operation' => $operationKey,
                ],
                'redirect' =>
                    $this->espionageHelper->isInfoGatheringOperation($operationKey) && $result['success']
                        ? route('dominion.op-center.show', $target->id)
                        : null,
            ] + $result;
    }

    /**
     * @param Dominion $dominion
     * @param string $operationKey
     * @param Dominion $target
     * @return array
     * @throws Exception
     */
    protected function performInfoGatheringOperation(Dominion $dominion, string $operationKey, Dominion $target): array
    {
        $operationInfo = $this->espionageHelper->getOperationInfo($operationKey);

        $selfSpa = $this->militaryCalculator->getSpyRatio($dominion, 'offense');
        $targetSpa = $this->militaryCalculator->getSpyRatio($target, 'defense');

        // You need at least some positive SPA to perform espionage operations
        if ($selfSpa === 0.0) {
            // Don't reduce spy strength by throwing an exception here
            throw new GameException("Your spy force is too weak to cast {$operationInfo['name']}. Please train some more spies.");
        }

        if ($targetSpa !== 0.0) {
            $successRate = $this->opsHelper->infoOperationSuccessChance($selfSpa, $targetSpa);

            // Wonders
            $successRate *= (1 - $target->getWonderPerkMultiplier('enemy_espionage_chance'));

            if (!random_chance($successRate)) {
                // Values (percentage)
                $spiesKilledBasePercentage = 0.25;

                // Forest Havens
                $forestHavenSpyCasualtyReduction = 3;
                $forestHavenSpyCasualtyReductionMax = 30;

                $spiesKilledMultiplier = (1 - min(
                    (($dominion->building_forest_haven / $this->landCalculator->getTotalLand($dominion)) * $forestHavenSpyCasualtyReduction),
                    ($forestHavenSpyCasualtyReductionMax / 100)
                ));

                $spyLossSpaRatio = ($targetSpa / $selfSpa);
                $spiesKilledPercentage = clamp($spiesKilledBasePercentage * $spyLossSpaRatio, 0.25, 1);

                // Techs
                $spiesKilledPercentage *= (1 + $dominion->getTechPerkMultiplier('spy_losses'));

                $unitsKilled = [];
                $spiesKilled = (int)floor(($dominion->military_spies * ($spiesKilledPercentage / 100)) * $spiesKilledMultiplier);
                if ($spiesKilled > 0) {
                    $unitsKilled['spies'] = $spiesKilled;
                    $dominion->military_spies -= $spiesKilled;
                }

                foreach ($dominion->race->units as $unit) {
                    if ($unit->getPerkValue('counts_as_spy_offense')) {
                        $unitKilledMultiplier = ((float)$unit->getPerkValue('counts_as_spy_offense') / 2) * ($spiesKilledPercentage / 100) * $spiesKilledMultiplier;
                        $unitKilled = (int)floor($dominion->{"military_unit{$unit->slot}"} * $unitKilledMultiplier);
                        if ($unitKilled > 0) {
                            $unitsKilled[strtolower($unit->name)] = $unitKilled;
                            $dominion->{"military_unit{$unit->slot}"} -= $unitKilled;
                        }
                    }
                }

                $target->stat_spies_executed += array_sum($unitsKilled);
                $dominion->stat_spies_lost += array_sum($unitsKilled);

                $unitsKilledStringParts = [];
                foreach ($unitsKilled as $name => $amount) {
                    $amountLabel = number_format($amount);
                    $unitLabel = str_plural(str_singular($name), $amount);
                    $unitsKilledStringParts[] = "{$amountLabel} {$unitLabel}";
                }
                $unitsKilledString = generate_sentence_from_array($unitsKilledStringParts);

                $this->notificationService
                    ->queueNotification('repelled_spy_op', [
                        'sourceDominionId' => $dominion->id,
                        'operationKey' => $operationKey,
                        'unitsKilled' => $unitsKilledString,
                    ])
                    ->sendNotifications($target, 'irregular_dominion');

                if ($unitsKilledString) {
                    $message = "The enemy has prevented our {$operationInfo['name']} attempt and managed to capture $unitsKilledString.";
                } else {
                    $message = "The enemy has prevented our {$operationInfo['name']} attempt.";
                }

                return [
                    'success' => false,
                    'message' => $message,
                    'alert-type' => 'warning',
                ];
            }
        }

        $infoOp = new InfoOp([
            'source_realm_id' => $dominion->realm->id,
            'target_realm_id' => $target->realm->id,
            'type' => $operationKey,
            'source_dominion_id' => $dominion->id,
            'target_dominion_id' => $target->id,
        ]);

        switch ($operationKey) {
            case 'barracks_spy':
                $infoOp->data = $this->infoMapper->mapMilitary($target);
                break;

            case 'castle_spy':
                $infoOp->data = $this->infoMapper->mapImprovements($target);
                break;

            case 'survey_dominion':
                $infoOp->data = $this->infoMapper->mapBuildings($target);
                break;

            case 'land_spy':
                $infoOp->data = $this->infoMapper->mapLand($target);
                break;

            default:
                throw new LogicException("Unknown info gathering operation {$operationKey}");
        }

        // Surreal Perception
        if ($this->spellCalculator->isSpellActive($target, 'surreal_perception')) {
            $this->notificationService
                ->queueNotification('received_spy_op', [
                    'sourceDominionId' => $dominion->id,
                    'operationKey' => $operationKey,
                ])
                ->sendNotifications($target, 'irregular_dominion');
        }

        $infoOp->save();

        return [
            'success' => true,
            'message' => 'Your spies infiltrate the target\'s dominion successfully and return with a wealth of information.',
            'redirect' => route('dominion.op-center.show', $target),
        ];
    }

    /**
     * @param Dominion $dominion
     * @param string $operationKey
     * @param Dominion $target
     * @return array
     * @throws Exception
     */
    protected function performResourceTheftOperation(Dominion $dominion, string $operationKey, Dominion $target): array
    {
        if ($dominion->round->hasOffensiveActionsDisabled())
        {
            throw new GameException('Theft has been disabled for the remainder of the round.');
        }

        $operationInfo = $this->espionageHelper->getOperationInfo($operationKey);

        $selfSpa = $this->militaryCalculator->getSpyRatio($dominion, 'offense');
        $targetSpa = $this->militaryCalculator->getSpyRatio($target, 'defense');

        // You need at least some positive SPA to perform espionage operations
        if ($selfSpa === 0.0) {
            // Don't reduce spy strength by throwing an exception here
            throw new GameException("Your spy force is too weak to cast {$operationInfo['name']}. Please train some more spies.");
        }

        if ($targetSpa !== 0.0) {
            $successRate = $this->opsHelper->theftOperationSuccessChance($selfSpa, $targetSpa);

            // Wonders
            $successRate *= (1 - $target->getWonderPerkMultiplier('enemy_espionage_chance'));

            if (!random_chance($successRate)) {
                // Values (percentage)
                $spiesKilledBasePercentage = 1;

                // Forest Havens
                $forestHavenSpyCasualtyReduction = 3;
                $forestHavenSpyCasualtyReductionMax = 30;

                $spiesKilledMultiplier = (1 - min(
                    (($dominion->building_forest_haven / $this->landCalculator->getTotalLand($dominion)) * $forestHavenSpyCasualtyReduction),
                    ($forestHavenSpyCasualtyReductionMax / 100)
                ));

                $spyLossSpaRatio = ($targetSpa / $selfSpa);
                $spiesKilledPercentage = clamp($spiesKilledBasePercentage * $spyLossSpaRatio, 0.5, 1.5);

                // Techs
                $spiesKilledPercentage *= (1 + $dominion->getTechPerkMultiplier('spy_losses'));

                $unitsKilled = [];
                $spiesKilled = (int)floor(($dominion->military_spies * ($spiesKilledPercentage / 100)) * $spiesKilledMultiplier);
                if ($spiesKilled > 0) {
                    $unitsKilled['spies'] = $spiesKilled;
                    $dominion->military_spies -= $spiesKilled;
                }

                foreach ($dominion->race->units as $unit) {
                    if ($unit->getPerkValue('counts_as_spy_offense')) {
                        $unitKilledMultiplier = ((float)$unit->getPerkValue('counts_as_spy_offense') / 2) * ($spiesKilledPercentage / 100) * $spiesKilledMultiplier;
                        $unitKilled = (int)floor($dominion->{"military_unit{$unit->slot}"} * $unitKilledMultiplier);
                        if ($unitKilled > 0) {
                            $unitsKilled[strtolower($unit->name)] = $unitKilled;
                            $dominion->{"military_unit{$unit->slot}"} -= $unitKilled;
                        }
                    }
                }

                $target->stat_spies_executed += array_sum($unitsKilled);
                $dominion->stat_spies_lost += array_sum($unitsKilled);

                $unitsKilledStringParts = [];
                foreach ($unitsKilled as $name => $amount) {
                    $amountLabel = number_format($amount);
                    $unitLabel = str_plural(str_singular($name), $amount);
                    $unitsKilledStringParts[] = "{$amountLabel} {$unitLabel}";
                }
                $unitsKilledString = generate_sentence_from_array($unitsKilledStringParts);

                $this->notificationService
                    ->queueNotification('repelled_resource_theft', [
                        'sourceDominionId' => $dominion->id,
                        'operationKey' => $operationKey,
                        'unitsKilled' => $unitsKilledString,
                    ])
                    ->sendNotifications($target, 'irregular_dominion');

                if ($unitsKilledString) {
                    $message = "The enemy has prevented our {$operationInfo['name']} attempt and managed to capture $unitsKilledString.";
                } else {
                    $message = "The enemy has prevented our {$operationInfo['name']} attempt.";
                }

                return [
                    'success' => false,
                    'message' => $message,
                    'alert-type' => 'warning',
                ];
            }
        }

        switch ($operationKey) {
            case 'steal_platinum':
                $resource = 'platinum';
                $constraints = [
                    'target_amount' => 2,
                    'self_production' => 75,
                    'spy_carries' => 45,
                ];
                break;

            case 'steal_food':
                $resource = 'food';
                $constraints = [
                    'target_amount' => 2,
                    'self_production' => 100,
                    'spy_carries' => 50,
                ];
                break;

            case 'steal_lumber':
                $resource = 'lumber';
                $constraints = [
                    'target_amount' => 5,
                    'self_production' => 75,
                    'spy_carries' => 50,
                ];
                break;

            case 'steal_mana':
                $resource = 'mana';
                $constraints = [
                    'target_amount' => 3,
                    'self_production' => 56,
                    'spy_carries' => 50,
                ];
                break;

            case 'steal_ore':
                $resource = 'ore';
                $constraints = [
                    'target_amount' => 5,
                    'self_production' => 68,
                    'spy_carries' => 50,
                ];
                break;

            case 'steal_gems':
                $resource = 'gems';
                $constraints = [
                    'target_amount' => 2,
                    'self_production' => 100,
                    'spy_carries' => 50,
                ];
                break;

            default:
                throw new LogicException("Unknown resource theft operation {$operationKey}");
        }

        $amountStolen = $this->getResourceTheftAmount($dominion, $target, $resource, $constraints);

        $dominion->{"resource_{$resource}"} += $amountStolen;
        $dominion->{"stat_total_{$resource}_stolen"} += $amountStolen;
        $target->{"resource_{$resource}"} -= $amountStolen;

        // Surreal Perception
        $sourceDominionId = null;
        if ($this->spellCalculator->isSpellActive($target, 'surreal_perception')) {
            $sourceDominionId = $dominion->id;
        }

        $this->notificationService
            ->queueNotification('resource_theft', [
                'sourceDominionId' => $sourceDominionId,
                'operationKey' => $operationKey,
                'amount' => $amountStolen,
                'resource' => $resource,
            ])
            ->sendNotifications($target, 'irregular_dominion');

        return [
            'success' => true,
            'message' => sprintf(
                'Your spies infiltrate the target\'s dominion successfully and return with %s %s.',
                number_format($amountStolen),
                $resource
            ),
            'redirect' => route('dominion.op-center.show', $target),
        ];
    }

    protected function getResourceTheftAmount(
        Dominion $dominion,
        Dominion $target,
        string $resource,
        array $constraints
    ): int {
        if ($this->spellCalculator->isSpellActive($target, 'fools_gold')) {
            if ($resource === 'platinum') {
                return 0;
            }
            if ($target->getTechPerkValue('improved_fools_gold') != 0 && ($resource === 'ore' || $resource === 'lumber')) {
                return 0;
            }
        }
        // Limit to percentage of target's raw production
        $maxTarget = true;
        if ($constraints['target_amount'] > 0) {
            $maxTarget = $target->{'resource_' . $resource} * $constraints['target_amount'] / 100;
        }

        // Limit to percentage of dominion's raw production
        $maxDominion = true;
        if ($constraints['self_production'] > 0) {
            if ($resource === 'platinum') {
                $maxDominion = floor($this->productionCalculator->getPlatinumProductionRaw($dominion) * $constraints['self_production'] / 100);
            } elseif ($resource === 'food') {
                $maxDominion = floor($this->productionCalculator->getFoodProductionRaw($dominion) * $constraints['self_production'] / 100);
            } elseif ($resource === 'lumber') {
                $maxDominion = floor($this->productionCalculator->getLumberProductionRaw($dominion) * $constraints['self_production'] / 100);
            } elseif ($resource === 'mana') {
                $maxDominion = floor($this->productionCalculator->getManaProductionRaw($dominion) * $constraints['self_production'] / 100);
            } elseif ($resource === 'ore') {
                $maxDominion = floor($this->productionCalculator->getOreProductionRaw($dominion) * $constraints['self_production'] / 100);
            } elseif ($resource === 'gems') {
                $maxDominion = floor($this->productionCalculator->getGemProductionRaw($dominion) * $constraints['self_production'] / 100);
            }
        }

        // Limit to amount carryable by spies
        $maxCarried = true;
        if ($constraints['spy_carries'] > 0) {
            // todo: refactor raw spies calculation
            $maxCarried = $this->militaryCalculator->getSpyRatioRaw($dominion) * $this->landCalculator->getTotalLand($dominion) * $constraints['spy_carries'];
        }

        // Techs
        $multiplier = (1 + $dominion->getTechPerkMultiplier('theft_gains') + $target->getTechPerkMultiplier('theft_losses'));

        return round(min($maxTarget, $maxDominion, $maxCarried) * $multiplier);
    }

    /**
     * @param Dominion $dominion
     * @param string $operationKey
     * @param Dominion $target
     * @return array
     * @throws Exception
     */
    protected function performHostileOperation(Dominion $dominion, string $operationKey, Dominion $target): array
    {
        if ($dominion->round->hasOffensiveActionsDisabled()) {
            throw new GameException('Black ops have been disabled for the remainder of the round.');
        }

        $operationInfo = $this->espionageHelper->getOperationInfo($operationKey);

        if ($this->espionageHelper->isWarOperation($operationKey)) {
            $warDeclared = ($dominion->realm->war_realm_id == $target->realm->id || $target->realm->war_realm_id == $dominion->realm->id);
            if (!$warDeclared && !in_array($target->id, $this->militaryCalculator->getRecentlyInvadedBy($dominion, 12))) {
                throw new GameException("You cannot perform {$operationInfo['name']} outside of war.");
            }
        }

        $selfSpa = $this->militaryCalculator->getSpyRatio($dominion, 'offense');
        $targetSpa = $this->militaryCalculator->getSpyRatio($target, 'defense');

        // You need at least some positive SPA to perform espionage operations
        if ($selfSpa === 0.0) {
            // Don't reduce spy strength by throwing an exception here
            throw new GameException("Your spy force is too weak to cast {$operationInfo['name']}. Please train some more spies.");
        }

        if ($targetSpa !== 0.0) {
            $successRate = $this->opsHelper->blackOperationSuccessChance($selfSpa, $targetSpa);

            // Wonders
            $successRate *= (1 - $target->getWonderPerkMultiplier('enemy_espionage_chance'));

            if (!random_chance($successRate)) {
                // Values (percentage)
                $spiesKilledBasePercentage = 1;

                // Forest Havens
                $forestHavenSpyCasualtyReduction = 3;
                $forestHavenSpyCasualtyReductionMax = 30;

                $spiesKilledMultiplier = (1 - min(
                    (($dominion->building_forest_haven / $this->landCalculator->getTotalLand($dominion)) * $forestHavenSpyCasualtyReduction),
                    ($forestHavenSpyCasualtyReductionMax / 100)
                ));

                $spyLossSpaRatio = ($targetSpa / $selfSpa);
                $spiesKilledPercentage = clamp($spiesKilledBasePercentage * $spyLossSpaRatio, 0.5, 1.5);

                // Techs
                $spiesKilledPercentage *= (1 + $dominion->getTechPerkMultiplier('spy_losses'));

                $unitsKilled = [];
                $spiesKilled = (int)floor(($dominion->military_spies * ($spiesKilledPercentage / 100)) * $spiesKilledMultiplier);
                if ($spiesKilled > 0) {
                    $unitsKilled['spies'] = $spiesKilled;
                    $dominion->military_spies -= $spiesKilled;
                }

                foreach ($dominion->race->units as $unit) {
                    if ($unit->getPerkValue('counts_as_spy_offense')) {
                        $unitKilledMultiplier = ((float)$unit->getPerkValue('counts_as_spy_offense') / 2) * ($spiesKilledPercentage / 100) * $spiesKilledMultiplier;
                        $unitKilled = (int)floor($dominion->{"military_unit{$unit->slot}"} * $unitKilledMultiplier);
                        if ($unitKilled > 0) {
                            $unitsKilled[strtolower($unit->name)] = $unitKilled;
                            $dominion->{"military_unit{$unit->slot}"} -= $unitKilled;
                        }
                    }
                }

                $target->stat_spies_executed += array_sum($unitsKilled);
                $dominion->stat_spies_lost += array_sum($unitsKilled);

                $unitsKilledStringParts = [];
                foreach ($unitsKilled as $name => $amount) {
                    $amountLabel = number_format($amount);
                    $unitLabel = str_plural(str_singular($name), $amount);
                    $unitsKilledStringParts[] = "{$amountLabel} {$unitLabel}";
                }
                $unitsKilledString = generate_sentence_from_array($unitsKilledStringParts);

                // Prestige Loss
                $prestigeLossString = '';
                if ($this->espionageHelper->isWarOperation($operationKey) && ($dominion->realm->war_realm_id == $target->realm->id && $target->realm->war_realm_id == $dominion->realm->id)) {
                    if ($dominion->prestige > 0) {
                        $dominion->prestige -= 1;
                        $prestigeLossString = 'You lost 1 prestige due to mutual war.';
                    }
                }

                $this->notificationService
                    ->queueNotification('repelled_spy_op', [
                        'sourceDominionId' => $dominion->id,
                        'operationKey' => $operationKey,
                        'unitsKilled' => $unitsKilledString,
                    ])
                    ->sendNotifications($target, 'irregular_dominion');

                if ($unitsKilledString) {
                    $message = sprintf(
                        'The enemy has prevented our %s attempt and managed to capture %s. %s',
                        $operationInfo['name'],
                        $unitsKilledString,
                        $prestigeLossString
                    );
                } else {
                    $message = sprintf(
                        'The enemy has prevented our %s attempt. %s',
                        $operationInfo['name'],
                        $prestigeLossString
                    );
                }

                return [
                    'success' => false,
                    'message' => $message,
                    'alert-type' => 'warning',
                ];
            }
        }

        $damageDealt = [];
        $totalDamage = 0;
        $baseDamage = (isset($operationInfo['percentage']) ? $operationInfo['percentage'] : 1) / 100;

        // War Duration
        if ($dominion->realm->war_realm_id == $target->realm->id && $target->realm->war_realm_id == $dominion->realm->id) {
            $warHours = $this->governmentService->getWarDurationHours($dominion->realm, $target->realm);
            $warReduction = clamp(0.35 / 36 * ($warHours - 60), 0, 0.35);
            $baseDamage *= (1 - $warReduction);
        }

        // Techs
        $baseDamage *= (1 + $target->getTechPerkMultiplier("enemy_{$operationInfo['key']}_damage"));

        if (isset($operationInfo['decreases'])) {
            foreach ($operationInfo['decreases'] as $attr) {
                $damage = $target->{$attr} * $baseDamage;

                // Damage reduction from Docks / Harbor
                if ($attr == 'resource_boats') {
                    $boatsProtected = $this->militaryCalculator->getBoatsProtected($target);
                    $damage = max($target->{$attr} - $boatsProtected, 0) * $baseDamage;
                }

                // Wonders
                $damage *= (1 + $target->getWonderPerkMultiplier("enemy_{$operationKey}_damage"));

                // Check for immortal wizards
                if ($target->race->getPerkValue('immortal_wizards') != 0 && $attr == 'military_wizards') {
                    $damage = 0;
                }

                if ($attr == 'wizard_strength') {
                    // Flat damage for Magic Snare
                    $damage = 100 * $baseDamage;
                    if ($damage > $target->wizard_strength) {
                        $damage = (int)$target->wizard_strength;
                    }
                    $target->{$attr} -= $damage;
                    $damage = (floor($target->{$attr} + $damage) - floor($target->{$attr}));
                } else {
                    // Rounded for all other damage types
                    $target->{$attr} -= round($damage);
                }

                $totalDamage += round($damage);
                $damageDealt[] = sprintf('%s %s', number_format($damage), dominion_attr_display($attr, $damage));

                // Update statistics
                if (isset($dominion->{"stat_{$operationInfo['key']}_damage"})) {
                    $dominion->{"stat_{$operationInfo['key']}_damage"} += round($damage);
                }
            }
        }
        if (isset($operationInfo['increases'])) {
            foreach ($operationInfo['increases'] as $attr) {
                $damage = $target->{$attr} * $baseDamage;
                $target->{$attr} += round($damage);
            }
        }

        // Prestige Gains
        $prestigeGainString = '';
        if ($this->espionageHelper->isWarOperation($operationKey) && $totalDamage > 0) {
            if ($dominion->realm->war_realm_id == $target->realm->id && $target->realm->war_realm_id == $dominion->realm->id) {
                $dominion->prestige += 2;
                $dominion->stat_spy_prestige += 2;
                $prestigeGainString = 'You were awarded 2 prestige due to mutual war.';
            } elseif (random_chance(0.25)) {
                $dominion->prestige += 1;
                $dominion->stat_spy_prestige += 1;
                $prestigeGainString = 'You were awarded 1 prestige due to war.';
            }
        }

        // Surreal Perception
        $sourceDominionId = null;
        if ($this->spellCalculator->isSpellActive($target, 'surreal_perception')) {
            $sourceDominionId = $dominion->id;
        }

        $damageString = generate_sentence_from_array($damageDealt);

        $this->notificationService
            ->queueNotification('received_spy_op', [
                'sourceDominionId' => $sourceDominionId,
                'operationKey' => $operationKey,
                'damageString' => $damageString,
            ])
            ->sendNotifications($target, 'irregular_dominion');

        return [
            'success' => true,
            'message' => sprintf(
                'Your spies infiltrate the target\'s dominion successfully, they lost %s. %s',
                $damageString,
                $prestigeGainString
            ),
            'redirect' => route('dominion.op-center.show', $target),
        ];
    }
}
