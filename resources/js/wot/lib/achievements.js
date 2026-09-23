/**
 * The badges and marks Wargaming awards, and what they are called.
 *
 * The art replaces its text label on screen, so these names are what a screen
 * reader and a hover get. Mastery uses the encyclopedia's own wording rather
 * than a paraphrase — a player reads "Ace Tanker" everywhere else in the game.
 *
 * The art itself is mirrored into public/ rather than hotlinked: the source URL
 * carries a client version that moves with each patch.
 */
export const MASTERY_NAMES = {
    third: 'Class III',
    second: 'Class II',
    first: 'Class I',
    ace: 'Ace Tanker',
};

export const MARK_NAMES = {
    one: '1 Mark of Excellence',
    two: '2 Marks of Excellence',
    three: '3 Marks of Excellence',
};

/**
 * `vehicle.mastery` is the raw 0-4 counter the API sends: 0 is none, then Class
 * III up to Ace Tanker. Indexed into to get the badge's key.
 */
export const MASTERY_KEYS = [null, 'third', 'second', 'first', 'ace'];
