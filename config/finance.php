<?php

/*
 * Everything the Financial Fleet sub-project (/finance) treats as a fixed list
 * or a published figure. Kept in config rather than in enums or tables so the
 * Vue side can be handed the same lists the validation rules read — see
 * HandleFinanceInertiaRequests, which shares `holding_types`, `position_classes`,
 * `flow_categories` and `frequencies` with every page.
 *
 * Rates throughout the sub-project are whole percentages: 4.25 means 4.25%.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Holding types
    |--------------------------------------------------------------------------
    |
    | One row of the fleet is a "holding": an asset or a liability. `side`
    | decides which column of the balance sheet it lands in. `rate` is the
    | default annual rate offered when one is added — growth for an asset, APR
    | for a liability.
    |
    | `tax` is how money inside the holding is taxed, which the Retirement
    | Strategizer reads: `deferred` is taxed on the way out (traditional),
    | `free` never again (Roth, HSA), `taxable` as it goes (everything else).
    |
    | `investable` marks what the Portfolio Projector picks up by default.
    |
    | A retirement account's `tax` here is only the fallback: its real treatment
    | comes from the row's own `tax_type` (see `retirement_tax_types` below).
    |
    | `holds` makes a type compound: it lists the types of holding that may be
    | nested inside one. A compound holding with accounts inside is worth their
    | sum, and each of them is taxed as the parent is. Only retirement accounts
    | are compound today; giving another type a `holds` list is all it takes.
    |
    */

    'holding_types' => [
        'cash' => ['label' => 'Checking / cash', 'side' => 'asset', 'group' => 'Cash', 'rate' => 0.0, 'tax' => 'taxable', 'investable' => false],
        'savings' => ['label' => 'Savings / HYSA', 'side' => 'asset', 'group' => 'Cash', 'rate' => 4.0, 'tax' => 'taxable', 'investable' => true],
        'brokerage' => ['label' => 'Brokerage account', 'side' => 'asset', 'group' => 'Investments', 'rate' => 7.0, 'tax' => 'taxable', 'investable' => true],
        'retirement' => [
            'label' => 'Retirement account', 'side' => 'asset', 'group' => 'Retirement', 'rate' => 7.0, 'tax' => 'deferred', 'investable' => true,
            'holds' => ['brokerage', 'savings', 'cash', 'crypto'],
        ],
        'hsa' => ['label' => 'HSA', 'side' => 'asset', 'group' => 'Retirement', 'rate' => 6.0, 'tax' => 'free', 'investable' => true],
        'crypto' => ['label' => 'Crypto', 'side' => 'asset', 'group' => 'Investments', 'rate' => 8.0, 'tax' => 'taxable', 'investable' => true],
        'real_estate' => ['label' => 'Real estate', 'side' => 'asset', 'group' => 'Property', 'rate' => 3.5, 'tax' => 'taxable', 'investable' => false],
        'business' => ['label' => 'Business', 'side' => 'asset', 'group' => 'Business', 'rate' => 5.0, 'tax' => 'taxable', 'investable' => false],
        'vehicle' => ['label' => 'Vehicle', 'side' => 'asset', 'group' => 'Property', 'rate' => -12.0, 'tax' => 'taxable', 'investable' => false],
        'other_asset' => ['label' => 'Other asset', 'side' => 'asset', 'group' => 'Other', 'rate' => 0.0, 'tax' => 'taxable', 'investable' => false],

        'mortgage' => ['label' => 'Mortgage', 'side' => 'liability', 'group' => 'Secured debt', 'rate' => 6.5, 'tax' => 'taxable', 'investable' => false],
        'auto_loan' => ['label' => 'Auto loan', 'side' => 'liability', 'group' => 'Secured debt', 'rate' => 7.0, 'tax' => 'taxable', 'investable' => false],
        'student_loan' => ['label' => 'Student loan', 'side' => 'liability', 'group' => 'Unsecured debt', 'rate' => 5.5, 'tax' => 'taxable', 'investable' => false],
        'credit_card' => ['label' => 'Credit card', 'side' => 'liability', 'group' => 'Unsecured debt', 'rate' => 22.0, 'tax' => 'taxable', 'investable' => false],
        'personal_loan' => ['label' => 'Personal loan', 'side' => 'liability', 'group' => 'Unsecured debt', 'rate' => 11.0, 'tax' => 'taxable', 'investable' => false],
        'other_liability' => ['label' => 'Other liability', 'side' => 'liability', 'group' => 'Other', 'rate' => 0.0, 'tax' => 'taxable', 'investable' => false],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retirement plans
    |--------------------------------------------------------------------------
    |
    | The two facts recorded about a retirement account. The plan is carried
    | for the rules that differ by plan — nothing reads it yet beyond the
    | label. The tax type is what the Retirement Strategizer sorts money by:
    | `tax` uses the same three words as the holding types above.
    |
    */

    'retirement_plans' => [
        'ira' => ['label' => 'IRA'],
        '401k' => ['label' => '401(k)'],
        '403b' => ['label' => '403(b)'],
        '457b' => ['label' => '457(b)'],
        'sep_simple_ira' => ['label' => 'SEP / SIMPLE IRA'],
    ],

    'retirement_tax_types' => [
        'traditional' => ['label' => 'Traditional', 'tax' => 'deferred'],
        'roth' => ['label' => 'Roth', 'tax' => 'free'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Position classes
    |--------------------------------------------------------------------------
    |
    | What sits inside an account. `return` is the default long-run annual
    | return offered when a position is added without one of its own.
    |
    */

    'position_classes' => [
        'stock' => ['label' => 'Individual stock', 'return' => 8.0],
        'index_fund' => ['label' => 'Index fund', 'return' => 7.0],
        'etf' => ['label' => 'ETF', 'return' => 7.0],
        'mutual_fund' => ['label' => 'Mutual fund', 'return' => 6.5],
        'bond' => ['label' => 'Bonds', 'return' => 4.0],
        'crypto' => ['label' => 'Crypto', 'return' => 8.0],
        'cash' => ['label' => 'Cash / money market', 'return' => 3.5],
        'other' => ['label' => 'Other', 'return' => 5.0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Flow categories
    |--------------------------------------------------------------------------
    |
    | A "flow" is money moving on a schedule: an income stream or an expense,
    | either standing alone or hung off a holding.
    |
    | `earned` income stops at the profile's retirement age unless the flow has
    | an end date of its own.
    |
    | `taxation` is the tax treatment a new income of that category starts on
    | (see `flow_taxations` below); the form lets it be changed. How much of an
    | income is taxed is the flow's own `taxed_portion`, 100% unless it is set
    | lower — there is no per-category share here any more.
    |
    | `essential` is the default for the budget's needs / wants split.
    |
    */

    'flow_categories' => [
        'income' => [
            'salary' => ['label' => 'W-2 salary', 'earned' => true, 'taxation' => 'w2'],
            'contract' => ['label' => 'Contract work', 'earned' => true, 'taxation' => 'self_employed'],
            'business' => ['label' => 'Business income', 'earned' => true, 'taxation' => 'income_only'],
            'rental' => ['label' => 'Rental income', 'earned' => false, 'taxation' => 'income_only'],
            'investment' => ['label' => 'Dividends / interest', 'earned' => false, 'taxation' => 'income_only'],
            'pension' => ['label' => 'Pension', 'earned' => false, 'taxation' => 'income_only'],
            'social_security' => ['label' => 'Social Security', 'earned' => false, 'taxation' => 'income_only'],
            'other_income' => ['label' => 'Other income', 'earned' => false, 'taxation' => 'income_only'],
        ],
        'expense' => [
            'housing' => ['label' => 'Housing', 'essential' => true],
            'utilities' => ['label' => 'Utilities', 'essential' => true],
            'food' => ['label' => 'Food', 'essential' => true],
            'transport' => ['label' => 'Transport', 'essential' => true],
            'insurance' => ['label' => 'Insurance', 'essential' => true],
            'healthcare' => ['label' => 'Healthcare', 'essential' => true],
            'debt' => ['label' => 'Debt payments', 'essential' => true],
            'taxes' => ['label' => 'Taxes', 'essential' => true],
            'maintenance' => ['label' => 'Maintenance', 'essential' => true],
            'management' => ['label' => 'Management fees', 'essential' => true],
            'contractors' => ['label' => 'Contractors', 'essential' => true],
            'subscriptions' => ['label' => 'Subscriptions', 'essential' => false],
            'entertainment' => ['label' => 'Entertainment', 'essential' => false],
            'travel' => ['label' => 'Travel', 'essential' => false],
            'other_expense' => ['label' => 'Other', 'essential' => false],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | How an income is taxed
    |--------------------------------------------------------------------------
    |
    | Deliberately four blunt treatments rather than the tax code. `schedule`
    | is which table the income is taxed on: `ordinary` is the income-tax
    | brackets, `capital_gains` the long-term capital gains ones, stacked on
    | top of ordinary income. `payroll` is the share of the profile's
    | self-employment tax rate also charged on it: all of it for the
    | self-employed, half for a W-2 wage (the employee's FICA), none otherwise.
    |
    | An income with no treatment at all is not taxed.
    |
    */

    'flow_taxations' => [
        'w2' => ['label' => 'W-2 wages', 'hint' => 'Income tax plus FICA', 'schedule' => 'ordinary', 'payroll' => 0.5],
        'self_employed' => ['label' => 'Self-employed', 'hint' => 'Income tax plus self-employment tax', 'schedule' => 'ordinary', 'payroll' => 1.0],
        'income_only' => ['label' => 'Income tax only', 'hint' => 'S-corp profit, a pension, rent', 'schedule' => 'ordinary', 'payroll' => 0.0],
        'capital_gains' => ['label' => 'Long-term capital gains', 'hint' => 'The capital gains brackets', 'schedule' => 'capital_gains', 'payroll' => 0.0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Frequencies
    |--------------------------------------------------------------------------
    |
    | `per_year` is how many times the amount lands in a year. `hourly` has none
    | of its own: it is the rate times the flow's hours a week, times 52. `once`
    | lands in the year of its start date and no other.
    |
    */

    'frequencies' => [
        'once' => ['label' => 'One time / per project', 'per_year' => 0],
        'hourly' => ['label' => 'Hourly', 'per_year' => 52],
        'weekly' => ['label' => 'Weekly', 'per_year' => 52],
        'biweekly' => ['label' => 'Every two weeks', 'per_year' => 26],
        'semimonthly' => ['label' => 'Twice a month', 'per_year' => 24],
        'monthly' => ['label' => 'Monthly', 'per_year' => 12],
        'quarterly' => ['label' => 'Quarterly', 'per_year' => 4],
        'annual' => ['label' => 'Annually', 'per_year' => 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Federal income tax
    |--------------------------------------------------------------------------
    |
    | Tax year 2026, from IRS Rev. Proc. 2025-32. Each bracket is [rate %, the
    | taxable income it runs up to]; null is the open top bracket. Update the
    | year and the figures together.
    |
    | Single and married-filing-jointly were checked against the IRS release
    | when this was written. Head-of-household's inner thresholds were not, and
    | should be before anyone relies on them.
    |
    */

    'tax' => [
        'year' => 2026,

        'filing_statuses' => [
            'single' => 'Single',
            'married_joint' => 'Married filing jointly',
            'head_of_household' => 'Head of household',
        ],

        'standard_deduction' => [
            'single' => 16100,
            'married_joint' => 32200,
            'head_of_household' => 24150,
        ],

        'brackets' => [
            'single' => [[10, 12400], [12, 50400], [22, 105700], [24, 201775], [32, 256225], [35, 640600], [37, null]],
            'married_joint' => [[10, 24800], [12, 100800], [22, 211400], [24, 403550], [32, 512450], [35, 768700], [37, null]],
            'head_of_household' => [[10, 17700], [12, 67450], [22, 105700], [24, 201750], [32, 256200], [35, 640600], [37, null]],
        ],

        /*
         * Long-term capital gains, tax year 2026, same shape as the brackets
         * above. The thresholds are of total taxable income: gains sit on top
         * of ordinary income and are taxed at whichever rates they reach.
         * Typed from memory of Rev. Proc. 2025-32 and not checked against it;
         * a profile can carry its own in place of these.
         */
        'capital_gains_brackets' => [
            'single' => [[0, 49450], [15, 545500], [20, null]],
            'married_joint' => [[0, 98900], [15, 613700], [20, null]],
            'head_of_household' => [[0, 66200], [15, 579600], [20, null]],
        ],

        /*
         * Self-employment tax: 12.4% Social Security plus 2.9% Medicare. A
         * W-2 wage pays half, the employee's FICA. The default for a
         * profile's own `se_tax_rate`. Charged on the whole amount — no wage
         * base cap, no 92.35% adjustment, no additional Medicare tax.
         */
        'self_employment_rate' => 15.3,

        /*
        |----------------------------------------------------------------------
        | State and local presets
        |----------------------------------------------------------------------
        |
        | Starting figures for the settings form, nothing more. Picking a state
        | there copies its deduction and brackets for the profile's filing
        | status into editable fields; what gets saved, and what the tools tax
        | with, is the profile's own copy (fin_profiles.state_brackets and
        | friends). Changing a figure here therefore reaches nobody who has
        | already saved — it only changes what the form offers next time.
        |
        | Same shapes as the federal tables above: a deduction per filing
        | status, and [rate %, taxable income it runs up to] brackets with
        | null on the open top one. An empty bracket list is a state that
        | does not tax this kind of income.
        |
        | `localities` are the local income taxes within a state that the form
        | can fill in the same way.
        |
        | Tennessee: no tax on wages or retirement income. The Hall tax on
        | interest and dividends was the last of it and ended with 2020.
        |
        | New York: tax year 2026, the first step of the rate cut in the FY2026
        | budget (the five lowest rates each down 0.1 point). Single and joint
        | thresholds were checked against published tables; head-of-household
        | thresholds were not. The high-income "recapture" that claws back the
        | benefit of the lower brackets is not modelled.
        |
        */

        'states' => [
            'TN' => [
                'label' => 'Tennessee',
                'note' => 'Tennessee has no tax on wages or retirement income.',
                'deduction' => ['single' => 0, 'married_joint' => 0, 'head_of_household' => 0],
                'brackets' => ['single' => [], 'married_joint' => [], 'head_of_household' => []],
                'localities' => [],
            ],

            'NY' => [
                'label' => 'New York',
                'note' => 'New York, tax year 2026. Check the figures against your own return.',
                'deduction' => ['single' => 8000, 'married_joint' => 16050, 'head_of_household' => 11200],
                'brackets' => [
                    'single' => [[3.9, 8500], [4.4, 11700], [5.15, 13900], [5.4, 80650], [5.9, 215400], [6.85, 1077550], [9.65, 5000000], [10.3, 25000000], [10.9, null]],
                    'married_joint' => [[3.9, 17150], [4.4, 23600], [5.15, 27900], [5.4, 161550], [5.9, 323200], [6.85, 2155350], [9.65, 5000000], [10.3, 25000000], [10.9, null]],
                    'head_of_household' => [[3.9, 12800], [4.4, 17650], [5.15, 20900], [5.4, 107650], [5.9, 269300], [6.85, 1616450], [9.65, 5000000], [10.3, 25000000], [10.9, null]],
                ],
                'localities' => [
                    'nyc' => [
                        'label' => 'New York City',
                        'deduction' => ['single' => 8000, 'married_joint' => 16050, 'head_of_household' => 11200],
                        'brackets' => [
                            'single' => [[3.078, 12000], [3.762, 25000], [3.819, 50000], [3.876, null]],
                            'married_joint' => [[3.078, 21600], [3.762, 45000], [3.819, 90000], [3.876, null]],
                            'head_of_household' => [[3.078, 14400], [3.762, 30000], [3.819, 60000], [3.876, null]],
                        ],
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Medicare IRMAA
    |--------------------------------------------------------------------------
    |
    | The income-related monthly adjustment to Medicare premiums, for 2026.
    | A year's premiums are set by the modified AGI on the return from two
    | years before, hence `income_year`. Update the three together.
    |
    | Each tier is [the modified AGI it runs up to, the Part B premium a month,
    | the Part D surcharge a month]; null is the open top tier. The first tier
    | is the standard premium, with no surcharge. Unlike an income-tax bracket
    | a tier is a cliff: the whole premium steps up once income crosses it.
    | Head of household files on the single thresholds.
    |
    | Shown on the settings page only — no tool charges it yet.
    |
    | The standard premium, the first and last thresholds and the range of
    | both columns were checked against published 2026 tables when this was
    | written. The three inner tiers were not, and should be before anyone
    | relies on them.
    |
    */

    'irmaa' => [
        'year' => 2026,
        'income_year' => 2024,

        'tiers' => [
            'single' => [[109000, 202.90, 0], [137000, 284.10, 14.50], [171000, 405.80, 37.50], [205000, 527.50, 60.40], [500000, 649.20, 83.30], [null, 689.90, 91.00]],
            'married_joint' => [[218000, 202.90, 0], [274000, 284.10, 14.50], [342000, 405.80, 37.50], [410000, 527.50, 60.40], [750000, 649.20, 83.30], [null, 689.90, 91.00]],
            'head_of_household' => [[109000, 202.90, 0], [137000, 284.10, 14.50], [171000, 405.80, 37.50], [205000, 527.50, 60.40], [500000, 649.20, 83.30], [null, 689.90, 91.00]],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Required minimum distributions
    |--------------------------------------------------------------------------
    |
    | The IRS Uniform Lifetime Table (in force since 2022): the divisor applied
    | to the prior year-end traditional balance at each age. SECURE 2.0 starts
    | RMDs at 73 for anyone born 1951-1959 and at 75 for anyone born 1960 on.
    |
    */

    'rmd' => [
        'start_age' => ['before_1960' => 73, 'from_1960' => 75],

        'divisors' => [
            72 => 27.4, 73 => 26.5, 74 => 25.5, 75 => 24.6, 76 => 23.7, 77 => 22.9, 78 => 22.0, 79 => 21.1,
            80 => 20.2, 81 => 19.4, 82 => 18.5, 83 => 17.7, 84 => 16.8, 85 => 16.0, 86 => 15.2, 87 => 14.4,
            88 => 13.7, 89 => 12.9, 90 => 12.2, 91 => 11.5, 92 => 10.8, 93 => 10.1, 94 => 9.5, 95 => 8.9,
            96 => 8.4, 97 => 7.8, 98 => 7.3, 99 => 6.8, 100 => 6.4, 101 => 6.0, 102 => 5.6, 103 => 5.2,
            104 => 4.9, 105 => 4.6, 106 => 4.3, 107 => 4.1, 108 => 3.9, 109 => 3.7, 110 => 3.5, 111 => 3.4,
            112 => 3.3, 113 => 3.1, 114 => 3.0, 115 => 2.9, 116 => 2.8, 117 => 2.7, 118 => 2.5, 119 => 2.3,
            120 => 2.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Roth conversion strategies
    |--------------------------------------------------------------------------
    |
    | The kinds of strategy the Retirement Strategizer can run, in the order
    | the form lists them. `ages` says which of the two age fields a kind
    | reads: `at` is a single year, `window` a first and last age. `fills`
    | marks the kinds that fill a tax bracket and so take a bracket to fill.
    |
    | `conversion_fill_rates` are the federal brackets a bracket-filling
    | strategy may fill to the top of. The top bracket is not among them: it
    | has no top.
    |
    */

    'conversion_strategies' => [
        'none' => ['label' => 'No conversion', 'description' => 'Leave traditional money where it is and take the RMDs as they come.', 'ages' => null],
        'lump' => ['label' => 'One large conversion', 'description' => 'Convert the whole traditional balance in a single year.', 'ages' => 'at'],
        'even' => ['label' => 'Even conversions before RMDs', 'description' => 'Empty the traditional balance in equal parts across a window of years, 65 through 72 unless you say otherwise.', 'ages' => 'window'],
        'fill_bracket' => ['label' => 'Fill a tax bracket each year', 'description' => 'Each year, convert just enough to bring income to the top of a tax bracket.', 'ages' => 'window', 'fills' => true],
        'fill_bracket_irmaa' => ['label' => 'Fill the tax or IRMAA bracket', 'description' => 'Each year, convert up to the top of the tax bracket or of the IRMAA tier you are already in, whichever comes first, so a conversion never raises your Medicare premiums.', 'ages' => 'window', 'fills' => true],
    ],

    'conversion_fill_rates' => [10, 12, 22, 24, 32, 35],

    /*
     * Where the tax on a conversion is paid from. `outside` is other savings
     * — the taxable bucket first — and leaves the whole conversion in the
     * Roth. `conversion` takes the tax out of the converted money, so less
     * reaches the Roth. The two split modes say how much comes from outside
     * (`amount` is what that figure is) and take the rest from the conversion.
     */
    'conversion_tax_payments' => [
        'outside' => ['label' => 'From savings outside the conversion', 'amount' => null],
        'conversion' => ['label' => 'From the converted money itself', 'amount' => null],
        'percent' => ['label' => 'A percentage from outside, the rest from the conversion', 'amount' => 'percent'],
        'flat' => ['label' => 'A flat amount a year from outside, the rest from the conversion', 'amount' => 'dollars'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monte Carlo
    |--------------------------------------------------------------------------
    |
    | `page_limit` is how many simulations — runs times strategies — are
    | worked out while the page loads; more than that goes to a queued job.
    | One simulation of a forty-year plan measured 0.5–1 ms, so the limit is
    | about a second and a half of work at the slow end.
    |
    */

    'monte_carlo' => [
        'page_limit' => 1500,
        'max_runs' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Planning defaults
    |--------------------------------------------------------------------------
    |
    | What a tool assumes until its form says otherwise. `scenario_spread` is
    | how far, in points of annual return, the Portfolio Projector's cautious
    | and optimistic lines sit either side of the expected one.
    |
    */

    'defaults' => [
        'inflation_rate' => 2.5,
        'retirement_age' => 65,
        'life_expectancy' => 92,
        'savings_rate' => 4.0,
        'market_return' => 7.0,
        'scenario_spread' => 2.0,
        // What an heir is taken to earn, before the inheritance, until a
        // conversion strategy says otherwise.
        'heir_income' => 100000,
    ],

];
