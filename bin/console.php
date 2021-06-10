<?php

declare(strict_types=1);

use PokemonGoApi\PogoAPI\CacheLoader;
use PokemonGoApi\PogoAPI\Collections\TranslationCollectionCollection;
use PokemonGoApi\PogoAPI\IO\RemoteFileLoader;
use PokemonGoApi\PogoAPI\Logger\PrintLogger;
use PokemonGoApi\PogoAPI\Parser\CustomTranslations;
use PokemonGoApi\PogoAPI\Parser\LeekduckParser;
use PokemonGoApi\PogoAPI\Parser\MasterDataParser;
use PokemonGoApi\PogoAPI\Parser\PokebattlerParser;
use PokemonGoApi\PogoAPI\Parser\PokemonGoImagesParser;
use PokemonGoApi\PogoAPI\Parser\SilphRoadResearchTaskParser;
use PokemonGoApi\PogoAPI\Parser\TranslationParser;
use PokemonGoApi\PogoAPI\RaidOverwrite\RaidBossOverwrite;
use PokemonGoApi\PogoAPI\Renderer\PokemonRenderer;
use PokemonGoApi\PogoAPI\Renderer\RaidBossGraphicRenderer;
use PokemonGoApi\PogoAPI\Renderer\RaidBossListRenderer;
use PokemonGoApi\PogoAPI\Renderer\ResearchTasksRenderer;
use PokemonGoApi\PogoAPI\Renderer\Types\RaidBossGraphicConfig;
use PokemonGoApi\PogoAPI\Types\BattleConfiguration;
use PokemonGoApi\PogoAPI\Types\PokemonType;
use PokemonGoApi\PogoAPI\Types\RaidBoss;
use PokemonGoApi\PogoAPI\Types\WeatherBoost;
use PokemonGoApi\PogoAPI\Util\GenerationDeterminer;
use PokemonGoApi\PogoAPI\Util\TypeWeatherCalculator;

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('UTC');

$logger = new PrintLogger();

$tmpDir      = __DIR__ . '/../data/tmp/';
$cacheLoader = new CacheLoader(
    new RemoteFileLoader($logger),
    new DateTimeImmutable(),
    $tmpDir,
    $logger
);

$logger->debug('Parse Files');
$masterData = new MasterDataParser();
$masterData->parseFile($cacheLoader->fetchGameMasterFile());

$pokemonImagesParser     = new PokemonGoImagesParser();
$pokemonAssetsCollection = $pokemonImagesParser->parseFile($cacheLoader->fetchPokemonImages());

$languageFiles      = $cacheLoader->fetchLanguageFiles();
$translationLoader  = new TranslationParser();
$translations       = new TranslationCollectionCollection();
$customTranslations = CustomTranslations::load();
foreach (TranslationParser::LANGUAGES as $languageName) {
    if (! isset($languageFiles['apk'][$languageName])) {
        continue;
    }

    $translations->addTranslationCollection(
        $translationLoader->loadLanguage(
            $languageName,
            $languageFiles['apk'][$languageName],
            $languageFiles['remote'][$languageName],
            $customTranslations[$languageName]
        )
    );
}

$pokemonRenderer = new PokemonRenderer($translations, $pokemonAssetsCollection);
$files           = [];

$logger->debug('Generate Types');

$typeWeatherCalculator = new TypeWeatherCalculator();
$outputTypes = [];
foreach (PokemonType::ALL_TYPES as $typeName) {
    $type = PokemonType::createFromPokemonType($typeName);
    $weatherBoost = [];
    foreach($typeWeatherCalculator->getWeatherBoost($type, PokemonType::none()) as $weatherBoost) {
        $weatherBoost = [
            'id' => $weatherBoost->getWeather(),
            'name' => [], // read weather_snow
            'assetName' => $weatherBoost->getAssetsName(),
        ];
    }
    $outputTypes[] = [
        'type' => $type->getType(),
        'name' => [], // read pokemon_type_fighting
        'doubleDamageFrom' => $type->getDoubleDamageFrom(),
        'halfDamageFrom' => $type->getHalfDamageFrom(),
        'noDamageFrom' => $type->getNoDamageFrom(),
        'weatherBoost' =>$weatherBoost
    ];
}
$files['types'] = $outputTypes;

$logger->debug('Generate Pokemon');
foreach ($masterData->getPokemonCollection()->toArray() as $pokemon) {
    $renderedPokemon = $pokemonRenderer->render($pokemon, $masterData->getAttacksCollection());

    $generation = GenerationDeterminer::fromDexNr($pokemon->getDexNr());

    $files['pokedex'][]                            = $renderedPokemon;
    $files['pokedex/generation/' . $generation][]  = $renderedPokemon;
    $files['pokedex/id/' . $pokemon->getDexNr()][] = $renderedPokemon;
    $files['pokedex/name/' . $pokemon->getId()][]  = $renderedPokemon;
    foreach ($pokemon->getTemporaryEvolutions() as $temporaryEvolution) {
        $files['pokedex/name/' . $temporaryEvolution->getId()][] = $renderedPokemon;
        $files['pokedex/mega'][]                                 = $renderedPokemon;
    }

    foreach ($pokemon->getPokemonRegionForms() as $regionForm) {
        if (strlen($regionForm->getFormId()) <= strlen($regionForm->getId()) + 1) {
            continue;
        }

        $regionName = strtolower(substr($regionForm->getFormId(), strlen($regionForm->getId()) + 1));
        if (! in_array($regionName, ['alola', 'galarian'])) {
            continue;
        }

        $files['pokedex/region/' . $regionName][] = $renderedPokemon;
    }
}

$logger->debug('Generate Tasks');
$taskParser    = new SilphRoadResearchTaskParser(
    $masterData->getPokemonCollection(),
    $translations->getCollection('English')
);
$tasks         = $taskParser->parseTasks(
    $cacheLoader->fetchTasksFromSilphroad()
);
$tasksRenderer = new ResearchTasksRenderer($translations, $masterData->getPokemonCollection());

$files['quests'] = $tasksRenderer->render(...$tasks);

$apidir = $tmpDir . 'api/';
foreach ($files as $file => $data) {
    @mkdir($apidir . dirname($file), 0777, true);
    if (count($data) === 1) {
        $data = reset($data);
    }

    file_put_contents($apidir . $file . '.json', json_encode($data, JSON_PRETTY_PRINT));
}

$logger->debug('Generate Raidbosses');
$raidBossHtmlList = $cacheLoader->fetchRaidBossesFromLeekduck();
$leekduckParser   = new LeekduckParser($masterData->getPokemonCollection());
$raidBosses       = $leekduckParser->parseRaidBosses($raidBossHtmlList);

$logger->debug(
    sprintf('Got %d remote raid bosses', count($raidBosses->toArray())),
    array_map(
        static fn (RaidBoss $raidBoss): string => $raidBoss->getPokemonId(),
        $raidBosses->toArray()
    )
);
$xmlData           = (array) (simplexml_load_string(
    file_get_contents(__DIR__ . '/../data/raidOverwrites.xml') ?: ''
) ?: []);
$raidBossOverwrite = new RaidBossOverwrite(
    json_decode(json_encode($xmlData['raidboss'] ?? []) ?: '[]'),
    $masterData->getPokemonCollection(),
    $logger
);
$raidBossOverwrite->overwrite($raidBosses);

$logger->debug(
    sprintf('Got %d raid bosses to render', count($raidBosses->toArray())),
    array_map(
        static fn (RaidBoss $raidBoss): string => $raidBoss->getPokemonId(),
        $raidBosses->toArray()
    )
);

$pokebattlerParser        = new PokebattlerParser(
    $cacheLoader,
    BattleConfiguration::easy(),
    BattleConfiguration::normal(),
    BattleConfiguration::hard()
);
$raidBossesWithDifficulty = $pokebattlerParser->appendResults($raidBosses);

$logger->debug('Generate Images');

$windowSize            = '0,0';
$raidBossImageRenderer = new RaidBossGraphicRenderer();
foreach ($translations->getCollections() as $translationName => $translationCollection) {
    $raidListDir = sprintf('%s/graphics/%s', $apidir, $translationName);
    if (! is_readable($raidListDir)) {
        @mkdir($raidListDir, 0777, true);
    }

    $raidGraphic = $raidBossImageRenderer->buildGraphic(
        $raidBossesWithDifficulty,
        $translationCollection,
        new RaidBossGraphicConfig()
    );

    file_put_contents(
        sprintf('%s/raidlist.svg', $raidListDir),
        $raidGraphic->getImageContent()
    );

    $raidGraphicB = $raidBossImageRenderer->buildGraphic(
        $raidBossesWithDifficulty,
        $translationCollection,
        new RaidBossGraphicConfig(RaidBossGraphicConfig::ORDER_LOW_TO_HIGH, false)
    );

    file_put_contents(
        sprintf('%s/raidlist_b.svg', $raidListDir),
        $raidGraphicB->getImageContent()
    );


    $windowSize = $raidGraphic->getWindowSize();
}

$logger->debug('Generate Raidboss.json');
$raidListRenderer = new RaidBossListRenderer();
$raidBossesList   = $raidListRenderer->buildList($raidBossesWithDifficulty, $translations);

file_put_contents($apidir . 'raidboss.json', json_encode([
    'currentList' => $raidBossesList,
    'graphics' => [
        'German' => [
            'svg' => '/api/graphics/German/raidlist.svg',
            'png' => '/api/graphics/German/raidlist.png',
            'sha512' => hash_file('sha512', $apidir . '/graphics/German/raidlist.svg'),
        ],
        'English' => [
            'svg' => '/api/graphics/English/raidlist.svg',
            'png' => '/api/graphics/English/raidlist.png',
            'sha512' => hash_file('sha512', $apidir . '/graphics/English/raidlist.svg'),
        ],
    ],
], JSON_PRETTY_PRINT));

$hashFiles = [
    $apidir . 'raidboss.json',
    $apidir . 'pokedex.json',
    $apidir . 'quests.json',
];

file_put_contents($apidir . 'hashes.json', json_encode(
    $cacheLoader->updateCaches($hashFiles),
    JSON_PRETTY_PRINT
));

$date   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$format = $date->format('Y-m-d H:i');
file_put_contents(
    __DIR__ . '/../public/version.css',
    <<<CSS
    #last-generated-display::before {
        content: "$format (UTC)";
    }
    CSS
);
$hasChanges = $cacheLoader->hasChanges();
$logger->debug(sprintf('CACHE_STATUS=%s', $hasChanges ? 'HAS_CHANGES' : 'NO_CHANGES'));
echo sprintf('::set-output name=CACHE_STATUS::%s' . PHP_EOL, $hasChanges ? 'HAS_CHANGES' : 'NO_CHANGES');
echo sprintf('::set-output name=WINDOW_SIZE::%s' . PHP_EOL, $windowSize);
