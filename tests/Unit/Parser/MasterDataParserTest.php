<?php

declare(strict_types=1);

namespace Tests\Unit\PokemonGoLingen\PogoAPI\Parser;

use PHPUnit\Framework\TestCase;
use PokemonGoLingen\PogoAPI\Parser\MasterDataParser;
use PokemonGoLingen\PogoAPI\Types\Pokemon;
use PokemonGoLingen\PogoAPI\Types\PokemonCombatMove;
use PokemonGoLingen\PogoAPI\Types\PokemonMove;
use PokemonGoLingen\PogoAPI\Types\PokemonStats;
use PokemonGoLingen\PogoAPI\Types\PokemonType;

use function array_map;

/**
 * @uses   \PokemonGoLingen\PogoAPI\Collections\AttacksCollection
 * @uses   \PokemonGoLingen\PogoAPI\Collections\PokemonCollection
 * @uses   \PokemonGoLingen\PogoAPI\Types\PokemonCombatMove
 * @uses   \PokemonGoLingen\PogoAPI\Types\PokemonMove
 * @uses   \PokemonGoLingen\PogoAPI\Types\PokemonType
 * @uses   \PokemonGoLingen\PogoAPI\Types\Pokemon
 * @uses   \PokemonGoLingen\PogoAPI\Types\PokemonStats
 *
 * @covers \PokemonGoLingen\PogoAPI\Parser\MasterDataParser
 */
class MasterDataParserTest extends TestCase
{
    public function testConstruct(): void
    {
        $sut = new MasterDataParser();
        self::assertEmpty($sut->getAttacksCollection()->toArray());
        self::assertEmpty($sut->getPokemonCollection()->toArray());
    }

    public function testParseFileWithPokemons(): void
    {
        $sut = new MasterDataParser();
        $sut->parseFile(__DIR__ . '/Fixtures/GAME_MASTER_LATEST.json');

        $pokemons = $sut->getPokemonCollection()->toArray();
        self::assertArrayHasKey('MEOWTH', $pokemons);
        $meowth        = $pokemons['MEOWTH'];
        $pokemonMeowth = new Pokemon(
            52,
            'MEOWTH',
            'MEOWTH',
            PokemonType::normal(),
            PokemonType::none()
        );
        $pokemonMeowth->setStats(
            new PokemonStats(120, 92, 78)
        );
        $pokemonMeowth->setQuickMoveNames('SCRATCH_FAST', 'BITE_FAST');
        $pokemonMeowth->setCinematicMoveNames('NIGHT_SLASH', 'DARK_PULSE', 'FOUL_PLAY');
        $pokemonMeowth->setEliteCinematicMoveNames('BODY_SLAM');
        foreach ($meowth->getPokemonRegionForms() as $form) {
            $pokemonMeowth->addPokemonRegionForm($form);
        }

        $forms = array_map(
            static fn (Pokemon $form): string => $form->getFormId(),
            $meowth->getPokemonRegionForms()
        );

        self::assertEquals(['MEOWTH_ALOLA', 'MEOWTH_GALARIAN'], $forms);
        self::assertEquals($pokemonMeowth, $meowth);
    }

    public function testParseFileWithPokemonMoves(): void
    {
        $sut = new MasterDataParser();
        $sut->parseFile(__DIR__ . '/Fixtures/GAME_MASTER_LATEST.json');

        $moves = $sut->getAttacksCollection()->toArray();
        self::assertCount(2, $moves);
        $move49 = new PokemonMove(
            49,
            'BUG_BUZZ',
            PokemonType::bug(),
            90.0,
            -50.0,
            3700.0,
            false
        );
        $move49->setCombatMove(new PokemonCombatMove(90.0, -60.0));
        self::assertEquals($move49, $moves['m49']);

        $move202 = new PokemonMove(
            202,
            'BITE_FAST',
            PokemonType::dark(),
            6.0,
            4.0,
            500.0,
            true
        );
        $move202->setCombatMove(new PokemonCombatMove(4.0, 2.0));
        self::assertEquals($move202, $moves['m202']);
    }
}