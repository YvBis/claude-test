<?php

declare(strict_types=1);

namespace App\Tests\Story;

use App\Domain\Collection\ValueObject\Theme;

/**
 * Every literal the demo story writes, kept away from the story logic.
 *
 * Nothing here is faker-derived: the fingerprint in `DemoStoryTest` hashes
 * database rows, and a random name would make it drift on every run. Collection
 * and item names are composed from these literals with a fixed index
 * (`sprintf`), which stays deterministic because the formula is a constant too.
 *
 * Item names repeat across collections on purpose - uniqueness is required only
 * within one collection, and the five names taken per collection never repeat
 * because each collection takes a contiguous slice of a 25-name cycle.
 */
final class DemoCatalog
{
    public const int USERS = 50;
    public const int COLLECTIONS_PER_THEME = 50;
    public const int ITEMS_PER_COLLECTION = 5;
    public const int TAGS = 40;
    public const int LIKES = 1500;
    public const int COMMENTS = 800;

    public const string ADMIN_EMAIL = 'admin@example.com';

    public const string USER_EMAIL_PATTERN = 'user%02d@example.com';

    public const string ADMIN_NAME = 'Demo Admin';

    public const string USER_NAME_PATTERN = 'Demo User %02d';

    /**
     * Ordered themes: the collection index is a multiple of 50, so theme order
     * decides which collections a given owner receives.
     *
     * @return list<Theme>
     */
    public static function themes(): array
    {
        return [Theme::books(), Theme::games(), Theme::movies(), Theme::drinks()];
    }

    /**
     * Ten bases per theme; each is numbered 1..5, which yields exactly fifty
     * unique collection names per theme.
     *
     * @return array<string, list<string>>
     */
    public static function collectionBases(): array
    {
        return [
            'books' => [
                'Fantasy Shelf', 'Science Fiction Shelf', 'History Shelf', 'Poetry Shelf', 'Detective Shelf',
                'Biography Shelf', 'Philosophy Shelf', 'Travel Shelf', 'Cooking Shelf', 'Art Shelf',
            ],
            'games' => [
                'Strategy Cabinet', 'Board Games Cabinet', 'Role Playing Cabinet', 'Card Games Cabinet', 'Puzzle Cabinet',
                'Console Cabinet', 'Retro Cabinet', 'Cooperative Cabinet', 'Party Cabinet', 'Wargame Cabinet',
            ],
            'movies' => [
                'Classic Cinema', 'Noir Cinema', 'Science Fiction Cinema', 'Documentary Cinema', 'Animation Cinema',
                'Drama Cinema', 'Comedy Cinema', 'Horror Cinema', 'European Cinema', 'Asian Cinema',
            ],
            'drinks' => [
                'Coffee Bar', 'Tea Bar', 'Whisky Bar', 'Wine Bar', 'Cocktail Bar',
                'Beer Bar', 'Juice Bar', 'Smoothie Bar', 'Cocoa Bar', 'Mineral Water Bar',
            ],
        ];
    }

    /**
     * Twenty-five names per theme; a collection takes five consecutive ones.
     *
     * @return array<string, list<string>>
     */
    public static function itemNames(): array
    {
        return [
            'books' => [
                'The Silent Library', 'A Map of Ashes', 'Northern Lights', 'The Glass Garden', 'Letters from Nowhere',
                'Winter Notes', 'The Long Corridor', 'Salt and Paper', 'The Quiet Machine', 'House of Echoes',
                'A Field of Stones', 'The Last Reader', 'Blue Hour', 'Small Histories', 'The Iron Orchard',
                'Paper Bridges', 'A Room of Maps', 'The Hidden Stair', 'Weather Reports', 'The Second Draft',
                'Notes on Silence', 'The Amber Key', 'Crossing Lines', 'The Open Window', 'Margins',
            ],
            'games' => [
                'Iron Harbor', 'Silent Fleet', 'The Long Siege', 'Trade Routes', 'Frozen Frontier',
                'Market Rising', 'The Fifth Column', 'Sand Empire', 'Night Convoy', 'Steel Harvest',
                'Border Dispute', 'The Quiet Front', 'Harbor Lights', 'Rail Network', 'Deep Mine',
                'The Cold Alliance', 'Open Waters', 'Signal Lost', 'The Last Turn', 'City Blocks',
                'Paper Empires', 'The Grand Route', 'Mountain Pass', 'Supply Lines', 'Final Round',
            ],
            'movies' => [
                'The Long Take', 'Night Train', 'A Quiet Street', 'The Blue Door', 'Last Screening',
                'Rain on Glass', 'The Empty Stage', 'Two Rivers', 'The Final Cut', 'Morning Light',
                'The Old Projector', 'City of Static', 'One More Scene', 'The Grey Coast', 'Silent Reel',
                'After the Credits', 'The Narrow Room', 'Slow Motion', 'The Last Frame', 'Open Ending',
                'Black and White', 'The Missing Reel', 'Closing Night', 'The Long Walk', 'Static',
            ],
            'drinks' => [
                'Ethiopia Guji', 'Kenya Nyeri', 'Colombia Huila', 'Brazil Cerrado', 'Guatemala Antigua',
                'Sumatra Mandheling', 'Costa Rica Tarrazu', 'Rwanda Nyungwe', 'Panama Geisha', 'Peru Cajamarca',
                'Darjeeling First Flush', 'Assam Gold', 'Sencha Morning', 'Oolong Iron', 'Pu Er Aged',
                'Lagavulin Sixteen', 'Glenfarclas Twelve', 'Yamazaki Twelve', 'Redbreast Fifteen', 'Springbank Ten',
                'Riesling Dry', 'Pinot Noir', 'Champagne Brut', 'Amarone', 'Sancerre',
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function textValues(): array
    {
        return [
            'books' => ['Hardcover', 'Paperback', 'First Edition', 'Signed Copy', 'Large Print', 'Pocket Size'],
            'games' => ['Two Players', 'Four Players', 'Cooperative', 'Competitive', 'Expansion', 'Base Game'],
            'movies' => ['Director Cut', 'Restored', 'Subtitled', 'Dubbed', 'Widescreen', 'Anniversary'],
            'drinks' => ['Light Roast', 'Medium Roast', 'Dark Roast', 'Single Origin', 'Blend', 'Decaf'],
        ];
    }

    /** @var list<int|float> */
    public const array NUMBERS = [7, 12, 3.5, 42, 0.5, 99];

    /** @var list<string> ISO-8601 dates in the mapper's strict `Y-m-d` form. */
    public const array DATES = ['2024-03-15', '2023-11-02', '2025-06-30', '2022-01-09', '2026-08-21', '2020-12-31'];

    /** @var list<bool> */
    public const array BOOLS = [true, false, true, true, false, true];

    /**
     * Forty tags, unique case-insensitively (`utf8mb4_0900_ai_ci` on the column),
     * two characters or longer, inside the domain whitelist.
     *
     * @var list<string>
     */
    public const array TAG_NAMES = [
        'fantasy', 'sci-fi', 'history', 'poetry', 'detective', 'biography', 'philosophy', 'travel',
        'cooking', 'art', 'strategy', 'cooperative', 'party', 'classic', 'noir', 'documentary',
        'animation', 'drama', 'comedy', 'horror', 'coffee', 'tea', 'whisky', 'wine',
        'cocktail', 'beer', 'juice', 'smoothie', 'cocoa', 'water', 'favorite', 'wishlist',
        'gift', 'rare', 'vintage', 'modern', 'signed', 'limited', 'complete', 'ongoing',
    ];

    /**
     * Comment texts are literals too, so the fingerprint can include them: the
     * promise covers every field the demo story writes.
     *
     * @var list<string>
     */
    public const array COMMENT_TEXTS = [
        'One of the best in the collection.',
        'Recommended for a quiet evening.',
        'Exactly what I was looking for.',
        'A little overrated, but still good.',
        'Would happily add this to my shelf.',
        'The description does not do it justice.',
        'Great value for the price.',
        'Not my favourite, but worth trying.',
        'Bought a second copy as a gift.',
        'Still holds up after all these years.',
    ];
}
