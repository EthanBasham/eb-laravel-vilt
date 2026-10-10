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
    | `itemized` marks an expense category whose flows may hold items of their
    | own: "Household expenses" is one line on the income & expenses page, set
    | up item by item on the monthly budget. A flow with items inside comes to
    | their sum; with none, its own amount stands as the estimate. An item
    | takes any of the other expense categories, never an itemized one.
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
            'household' => ['label' => 'Household expenses', 'essential' => true, 'itemized' => true],
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
    | Automated transfers
    |--------------------------------------------------------------------------
    |
    | What a transfer moves from one holding to another each month, once the
    | month's income, expenses and growth have landed. `amount` says what the
    | transfer's amount field means for the kind (null: it is an optional cap);
    | `keeps` which end its `keep_balance` is held in. A transfer never takes
    | an asset below zero, and never pays a debt past nothing owed.
    |
    */

    'transfer_kinds' => [
        'sweep' => ['label' => 'Sweep what is left over', 'description' => 'Move everything above a balance you choose to keep in the source.', 'amount' => null, 'keeps' => 'from'],
        'fixed' => ['label' => 'A fixed amount each month', 'description' => 'Move the same amount every month, as far as the source has it.', 'amount' => 'required', 'keeps' => null],
        'top_up' => ['label' => 'Top up the destination', 'description' => 'Whenever the destination falls below a balance, refill it from the source.', 'amount' => null, 'keeps' => 'to'],
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

        /*
         * The additional standard deduction for the aged, from 65. $1,650 a
         * spouse on a joint return (both counted, as though the same age),
         * $2,050 for anyone unmarried. Not the temporary $6,000 senior
         * deduction of 2025-2028, which phases out above $75,000 of income
         * ($150,000 joint) and is not modelled.
         */
        'additional_deduction' => [
            'age' => 65,
            'single' => 2050,
            'married_joint' => 3300,
            'head_of_household' => 2050,
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
    | Shown on the settings page, and charged by the Roth conversion and
    | withdrawal tools.
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

    /*
     * Qualified charitable distributions: money given straight from a
     * traditional IRA to a charity, which is not income. `limit` is the most
     * one person may give that way in the tax year above, and rises with
     * inflation; a profile may set its own. `age` is the year a person
     * reaches 70½ in when born in the first half of a year; born in the
     * second, it is the year after.
     */
    'qcd' => ['limit' => 111000, 'age' => 70],

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
    | marks the kinds that fill a tax bracket and so take a bracket to fill;
    | `amount` the kind that converts a set amount a year, and so takes one.
    |
    | `conversion_fill_rates` are the federal brackets a bracket-filling
    | strategy may fill to the top of. The top bracket is not among them: it
    | has no top.
    |
    */

    'conversion_strategies' => [
        'none' => ['label' => 'No conversion', 'description' => 'Leave traditional accounts alone and take the RMDs in full.', 'ages' => null],
        'lump' => ['label' => 'One large conversion', 'description' => 'Convert the whole traditional balance in a single year.', 'ages' => 'at'],
        'even' => ['label' => 'Even conversions before RMDs', 'description' => 'Empty the traditional balance in equal parts across the age range.', 'ages' => 'window'],
        'fixed' => ['label' => 'Fixed amount each year', 'description' => 'Convert the same amount every year across the age range.', 'ages' => 'window', 'amount' => true],
        'fill_bracket' => ['label' => 'Fill a tax bracket each year', 'description' => 'Each year, convert just enough to bring income to the top of a tax bracket.', 'ages' => 'window', 'fills' => true],
        'fill_bracket_irmaa' => ['label' => 'Fill the tax or IRMAA bracket', 'description' => 'Each year, convert up to the top of the tax bracket or to the top of the IRMAA tier you are already in.', 'ages' => 'window', 'fills' => true],
    ],

    'conversion_fill_rates' => [10, 12, 22, 24, 32, 35],

    /*
     * How many columns the Roth report holds, each a strategy run on one
     * projection. Only the report is simulated when the page loads; a
     * strategy in no column costs it nothing. `default` is where the report
     * stops filling by itself when starter strategies are added in bulk;
     * `max` is the most it will take when columns are added by hand.
     */
    'conversion_comparison' => ['default' => 6, 'max' => 12],

    /*
     * Where the tax on a conversion is paid from. `outside` is the year's own
     * surplus — income less expenses, other tax and IRMAA — and caps the
     * conversion at what that surplus can pay the tax on; the whole of what
     * is converted reaches the Roth. `conversion` takes the tax out of the converted money, so less
     * reaches the Roth. The two split modes say how much comes from outside
     * (`amount` is what that figure is) and take the rest from the conversion.
     */
    'conversion_tax_payments' => [
        'outside' => ['label' => 'From the year\'s spare income (caps the conversion)', 'amount' => null],
        'conversion' => ['label' => 'From the converted money itself', 'amount' => null],
        'percent' => ['label' => 'A percentage from outside, the rest from the conversion', 'amount' => 'percent'],
        'flat' => ['label' => 'A flat amount a year from outside, the rest from the conversion', 'amount' => 'dollars'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Social Security
    |--------------------------------------------------------------------------
    |
    | What the claiming tool needs from the Social Security Act, none of which
    | is indexed to inflation.
    |
    | `full_retirement_age` is [the last birth year it applies to, years,
    | months]; null is everyone born later. Someone born on 1 January counts
    | with the year before, which is not modelled.
    |
    | A benefit claimed early loses 5/9 of 1% for each of the first 36 months
    | and 5/12 of 1% for each month beyond; one delayed past full retirement
    | age gains 2/3 of 1% a month, up to 70. A spouse's benefit on the other's
    | record is half that person's full benefit, loses 25/36 of 1% for each of
    | the first 36 months early and 5/12 of 1% beyond, and earns nothing for
    | waiting.
    |
    | `taxation` is the provisional-income test: other income plus half the
    | benefit, against two thresholds that have never been raised. Up to half
    | the benefit is taxable above the first, up to 85% above the second.
    |
    */

    'social_security' => [
        'earliest_age' => 62,
        'latest_age' => 70,

        'full_retirement_age' => [
            [1937, 65, 0], [1938, 65, 2], [1939, 65, 4], [1940, 65, 6], [1941, 65, 8], [1942, 65, 10],
            [1954, 66, 0], [1955, 66, 2], [1956, 66, 4], [1957, 66, 6], [1958, 66, 8], [1959, 66, 10],
            [null, 67, 0],
        ],

        'early_reduction' => ['first_months' => 36, 'first_rate' => 5 / 9, 'later_rate' => 5 / 12],
        'spousal_reduction' => ['first_months' => 36, 'first_rate' => 25 / 36, 'later_rate' => 5 / 12],
        'delayed_credit' => 2 / 3,
        'spousal_share' => 0.5,

        'taxation' => [
            'single' => [25000, 34000],
            'married_joint' => [32000, 44000],
            'head_of_household' => [25000, 34000],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retirement withdrawals
    |--------------------------------------------------------------------------
    |
    | The orders the withdrawal tool can draw the three buckets down in.
    | `order` is which bucket is emptied first: `taxable` savings, `deferred`
    | (traditional) and `free` (Roth, HSA). `proportional` takes from each in
    | proportion to what it holds; `fills` draws traditional money up to the
    | top of a tax bracket first and only then follows the order.
    |
    | `spending_rules` say how much a retired year takes from the accounts.
    | `projection` takes whatever the year's expenses and tax leave uncovered,
    | and saves a surplus; the other two take a set amount whatever the year
    | needs, and what that leaves to spend is the answer. `reads` is which of
    | the strategy's two figures the rule takes. A working year always runs
    | on the projection.
    |
    */

    'withdrawal_strategies' => [
        'conventional' => ['label' => 'Taxable, then traditional, then Roth', 'description' => 'The conventional order: spend ordinary savings first, leave the Roth to grow longest.', 'order' => ['taxable', 'deferred', 'free']],
        'traditional_first' => ['label' => 'Traditional first', 'description' => 'Draw traditional money down before anything else, to shrink the RMDs to come.', 'order' => ['deferred', 'taxable', 'free']],
        'roth_first' => ['label' => 'Roth first', 'description' => 'Spend tax-free money first. Usually the dearest order; here for the comparison.', 'order' => ['free', 'taxable', 'deferred']],
        'proportional' => ['label' => 'From each in proportion', 'description' => 'Take from every bucket in proportion to what it holds, so the mix stays the same.', 'order' => ['taxable', 'deferred', 'free'], 'proportional' => true],
        'bracket_fill' => ['label' => 'Fill a tax bracket from traditional', 'description' => 'Each year take traditional money up to the top of a tax bracket, then savings, then Roth, so cheap room in the brackets is never wasted.', 'order' => ['taxable', 'free', 'deferred'], 'fills' => true],
    ],

    'spending_rules' => [
        'projection' => ['label' => 'Whatever the projection needs', 'description' => 'Each year, take what its expenses and tax leave uncovered. Anything spare is saved.', 'reads' => null],
        'fixed' => ['label' => 'A fixed amount, rising with inflation', 'description' => 'Take the same amount every retired year, in today\'s dollars. 4% of the starting balance is the classic rule.', 'reads' => 'amount'],
        'percent' => ['label' => 'A percentage of what is left', 'description' => 'Take a share of the balance each year: the amount moves with the market, and the money never quite runs out.', 'reads' => 'percent'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monte Carlo
    |--------------------------------------------------------------------------
    |
    | `page_limit` is how many simulations — runs times strategies — are
    | worked out while the page loads; more than that goes to a queued job.
    | One simulation of a forty-year plan measured 1–6 ms on 2026-10-06,
    | depending on the kind of strategy, so the limit is a few seconds of work
    | at the slow end.
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
        // What a fixed-amount strategy converts a year until it is told
        // otherwise, in today's dollars.
        'conversion_amount' => 100000,
        // The classic "4% rule", for a percentage-of-balance spending rule.
        'withdrawal_percent' => 4.0,
    ],

];
