<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <style>
        @page {
            margin: 17mm 14mm 20mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #243430;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            line-height: 1.45;
            background: #ffffff;
        }

        h1,
        h2,
        h3,
        p {
            margin: 0;
        }

        /*
         |--------------------------------------------------------------------------
         | Brand header
         |--------------------------------------------------------------------------
         */

        .brand-header {
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid #b7d5ce;
        }

        .brand-header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .brand-left {
            vertical-align: top;
        }

        .brand-right {
            width: 38%;
            vertical-align: top;
            text-align: right;
        }

        .brand-lockup {
            width: 100%;
            border-collapse: collapse;
        }

        .logo-cell {
            width: 42px;
            vertical-align: top;
        }

        .logo {
            display: block;
            width: 34px;
            height: 34px;
        }

        .brand-name {
            margin-top: 1px;
            color: #172d28;
            font-size: 18px;
            font-weight: bold;
            line-height: 1.05;
        }

        .brand-name-accent {
            color: #118774;
        }

        .edition {
            margin-top: 3px;
            color: #75817f;
            font-size: 6.5px;
            font-weight: bold;
            letter-spacing: 1.55px;
            text-transform: uppercase;
        }

        .report-identity {
            margin-top: 12px;
        }

        .report-title {
            color: #1d2e2a;
            font-size: 14px;
            font-weight: bold;
            line-height: 1.2;
        }

        .period {
            margin-top: 3px;
            color: #687773;
            font-size: 8px;
        }

        .shop-name {
            color: #1c2d29;
            font-size: 11px;
            font-weight: bold;
            line-height: 1.3;
        }

        .generated-label {
            margin-top: 4px;
            color: #76837f;
            font-size: 7.5px;
            font-weight: normal;
        }

        .brand-accent {
            width: 28px;
            height: 2px;
            margin-top: 8px;
            margin-left: auto;
            background: #118774;
        }

        /*
         |--------------------------------------------------------------------------
         | Typography
         |--------------------------------------------------------------------------
         */

        h2 {
            margin: 17px 0 7px;
            color: #1b725f;
            font-size: 9.5px;
            font-weight: bold;
            letter-spacing: .25px;
        }

        h3 {
            margin-bottom: 4px;
            color: #233531;
            font-size: 9px;
        }

        .muted {
            color: #6e7b78;
        }

        .small {
            font-size: 7.5px;
        }

        /*
         |--------------------------------------------------------------------------
         | Executive summary
         |--------------------------------------------------------------------------
         */

        .summary-heading {
            margin-top: 0;
        }

        .metrics {
            width: 100%;
            border-collapse: separate;
            border-spacing: 5px 0;
            margin-left: -5px;
        }

        .metrics td {
            width: 25%;
            padding: 10px 9px 9px;
            vertical-align: top;
            background: #f6faf8;
            border: 1px solid #dbe8e4;
            border-radius: 4px;
        }

        .metric-label {
            color: #71807c;
            font-size: 6.5px;
            font-weight: bold;
            letter-spacing: .3px;
            text-transform: uppercase;
        }

        .metric-value {
            margin-top: 5px;
            color: #183d34;
            font-size: 12.5px;
            font-weight: bold;
            line-height: 1.15;
        }

        .metric-note {
            margin-top: 4px;
            color: #75817f;
            font-size: 7px;
        }

        /*
         |--------------------------------------------------------------------------
         | Content blocks
         |--------------------------------------------------------------------------
         */

        .cols {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px 0;
            margin-left: -8px;
        }

        .cols td {
            width: 50%;
            vertical-align: top;
        }

        .box {
            padding: 9px 10px;
            border: 1px solid #dce7e4;
            border-radius: 3px;
            background: #ffffff;
        }

        .highlight-box {
            padding: 9px 10px;
            border: 1px solid #d8e8e3;
            border-left: 3px solid #118774;
            background: #f8fbfa;
        }

        /*
         |--------------------------------------------------------------------------
         | Data tables
         |--------------------------------------------------------------------------
         */

        table.data {
            width: 100%;
            border-collapse: collapse;
        }

        table.data thead {
            display: table-header-group;
        }

        table.data tr {
            page-break-inside: avoid;
        }

        table.data th {
            padding: 6px;
            border-bottom: 1px solid #cfe0db;
            background: #eef6f3;
            color: #536560;
            font-size: 6.5px;
            font-weight: bold;
            letter-spacing: .35px;
            text-align: left;
            text-transform: uppercase;
        }

        table.data td {
            padding: 6px;
            border-bottom: 1px solid #e6eeeb;
            color: #2c3b37;
            vertical-align: top;
        }

        table.data tbody tr:last-child td {
            border-bottom: 0;
        }

        table.data td:first-child {
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        table.data .num {
            text-align: right;
            white-space: nowrap;
        }

        .strong-row td {
            color: #183d34;
            font-weight: bold;
        }

        .warn {
            color: #9a650e;
            font-weight: bold;
        }

        .danger {
            color: #a8493f;
            font-weight: bold;
        }

        /*
         |--------------------------------------------------------------------------
         | Section/page behaviour
         |--------------------------------------------------------------------------
         */

        .section {
            page-break-inside: avoid;
        }

        .section-breakable {
            page-break-inside: auto;
        }

        .section-title {
            page-break-after: avoid;
        }

        /*
         |--------------------------------------------------------------------------
         | Footer
         |--------------------------------------------------------------------------
         */

        .footer {
            position: fixed;
            right: 0;
            bottom: -13mm;
            left: 0;
            padding-top: 5px;
            border-top: 1px solid #d6e3df;
            color: #71807c;
            font-size: 7px;
        }

        .footer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .footer-left {
            text-align: left;
        }

        .footer-right {
            text-align: right;
        }

        .footer-brand {
            color: #49635c;
            font-weight: bold;
        }
    </style>
</head>

<body>

    {{-- ================================================================
        BRAND HEADER
    ================================================================= --}}

    <div class="brand-header">
        <table class="brand-header-table">
            <tr>
                <td class="brand-left">

                    <table class="brand-lockup">
                        <tr>
                            @if($logoDataUri)
                                <td class="logo-cell">
                                    <img
                                        class="logo"
                                        src="{{ $logoDataUri }}"
                                        alt="NileBit POS"
                                    >
                                </td>
                            @endif

                            <td>
                                <div class="brand-name">
                                    NileBit <span class="brand-name-accent">POS</span>
                                </div>
                                <div class="edition">For Retail</div>
                            </td>
                        </tr>
                    </table>

                    <div class="report-identity">
                        <div class="report-title">{{ $reportTitle }}</div>
                        <p class="period">
                            {{ $range->from->format('j M Y') }}
                            –
                            {{ $range->to->format('j M Y') }}
                        </p>
                    </div>

                </td>

                <td class="brand-right">
                    <div class="shop-name">{{ $shop->name }}</div>

                    <div class="generated-label">
                        Generated {{ $generatedAt->format('j M Y, H:i T') }}
                    </div>

                    <div class="brand-accent"></div>
                </td>
            </tr>
        </table>
    </div>


    {{-- ================================================================
        EXECUTIVE SUMMARY
    ================================================================= --}}

    <h2 class="summary-heading">Executive summary</h2>

    <table class="metrics">
        <tr>
            <td>
                <div class="metric-label">Net sales</div>

                <div class="metric-value">
                    UGX {{ number_format($report['sales']['summary']['net_sales']) }}
                </div>

                <div class="metric-note">
                    {{ $report['sales']['summary']['sales_count'] }} sale(s)
                </div>
            </td>

            <td>
                <div class="metric-label">Customer credit</div>

                <div class="metric-value">
                    UGX {{ number_format($report['debt']['summary']['total_owed']) }}
                </div>

                <div class="metric-note">
                    {{ $report['debt']['summary']['customers_owing'] }} customer(s) owing
                </div>
            </td>

            <td>
                <div class="metric-label">Stock warnings</div>

                <div class="metric-value">
                    {{ $report['stock']['summary']['out'] + $report['stock']['summary']['low'] }}
                </div>

                <div class="metric-note">
                    {{ $report['stock']['summary']['out'] }} out ·
                    {{ $report['stock']['summary']['low'] }} low
                </div>
            </td>

            @if(isset($report['profit']))
                <td>
                    <div class="metric-label">Profit after expenses</div>

                    <div class="metric-value">
                        UGX {{ number_format($report['profit']['summary']['operating_profit']) }}
                    </div>

                    <div class="metric-note">Owner view</div>
                </td>
            @else
                <td>
                    <div class="metric-label">Expenses</div>

                    <div class="metric-value">
                        UGX {{ number_format($report['expenses']['total']) }}
                    </div>

                    <div class="metric-note">Recorded this period</div>
                </td>
            @endif
        </tr>
    </table>


    {{-- ================================================================
        SALES / EXPENSES
    ================================================================= --}}

    <table class="cols">
        <tr>
            <td>
                <h2 class="section-title">Sales</h2>

                <div class="box">
                    <table class="data">
                        <tbody>
                            <tr>
                                <td>Gross sales</td>
                                <td class="num">
                                    UGX {{ number_format($report['sales']['summary']['gross_sales']) }}
                                </td>
                            </tr>

                            <tr>
                                <td>Refunds</td>
                                <td class="num">
                                    UGX {{ number_format($report['sales']['summary']['refunds']) }}
                                </td>
                            </tr>

                            <tr class="strong-row">
                                <td>Net sales</td>
                                <td class="num">
                                    UGX {{ number_format($report['sales']['summary']['net_sales']) }}
                                </td>
                            </tr>

                            @foreach(array_slice($report['sales']['payment_methods'], 0, 4) as $method)
                                <tr>
                                    <td>
                                        {{ ucwords(strtolower(str_replace('_', ' ', $method['method']))) }}
                                    </td>

                                    <td class="num">
                                        UGX {{ number_format($method['amount']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </td>

            <td>
                <h2 class="section-title">Expenses</h2>

                <div class="box">
                    @if($report['expenses']['categories'])
                        <table class="data">
                            <tbody>
                                @foreach(array_slice($report['expenses']['categories'], 0, 5) as $expense)
                                    <tr>
                                        <td>{{ $expense['category'] }}</td>

                                        <td class="num">
                                            UGX {{ number_format($expense['amount']) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="muted">
                            No expenses recorded in this period.
                        </p>
                    @endif
                </div>
            </td>
        </tr>
    </table>


    {{-- ================================================================
        PRODUCTS
    ================================================================= --}}

    @if($report['sales']['products'])
        <div class="section-breakable">
            <h2 class="section-title">Best-selling products</h2>

            <table class="data">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="num">Quantity</th>
                        <th class="num">Revenue</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach(array_slice($report['sales']['products'], 0, 8) as $product)
                        <tr>
                            <td>{{ $product['name'] }}</td>

                            <td class="num">
                                {{ $product['quantity'] }}
                            </td>

                            <td class="num">
                                UGX {{ number_format($product['revenue']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif


    {{-- ================================================================
        STOCK
    ================================================================= --}}

    @if($report['stock']['summary']['out'] || $report['stock']['summary']['low'])
        <div class="section-breakable">
            <h2 class="section-title">Stock requiring attention</h2>

            <table class="data">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="num">On hand</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach(
                        array_slice(
                            array_filter(
                                $report['stock']['page']['data'],
                                fn ($item) => $item['status'] !== 'ok'
                            ),
                            0,
                            25
                        ) as $item
                    )
                        <tr>
                            <td>{{ $item['name'] }}</td>

                            <td class="num">
                                {{ $item['stock'] }} {{ $item['unit'] }}
                            </td>

                            <td class="{{ $item['status'] === 'out' ? 'danger' : 'warn' }}">
                                {{ ucfirst($item['status']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif


    {{-- ================================================================
        CUSTOMERS
    ================================================================= --}}

    @if($report['debt']['customers'])
        <div class="section-breakable">
            <h2 class="section-title">Customer credit</h2>

            <table class="data">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th class="num">Outstanding</th>
                        <th class="num">Overdue</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach(array_slice($report['debt']['customers'], 0, 8) as $customer)
                        <tr>
                            <td>{{ $customer['name'] }}</td>

                            <td class="num">
                                UGX {{ number_format($customer['balance']) }}
                            </td>

                            <td class="num">
                                {{ $customer['overdue']
                                    ? 'UGX '.number_format($customer['overdue'])
                                    : '—'
                                }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif


    {{-- ================================================================
        SUPPLIERS
    ================================================================= --}}

    @if($report['suppliers']['total_owed'] || $report['suppliers']['bought'])
        <div class="section">
            <h2 class="section-title">Suppliers</h2>

            <div class="highlight-box">
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td>
                            <span class="muted small">Outstanding supplier balance</span><br>

                            <strong>
                                UGX {{ number_format($report['suppliers']['total_owed']) }}
                            </strong>
                        </td>

                        <td style="text-align:right;">
                            <span class="muted small">Purchases this period</span><br>

                            <strong>
                                UGX {{ number_format($report['suppliers']['bought']) }}
                            </strong>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    @endif


    {{-- ================================================================
        OWNER-ONLY PROFIT
    ================================================================= --}}

    @if(isset($report['profit']))
        <div class="section">
            <h2 class="section-title">Owner financial summary</h2>

            <div class="highlight-box">
                <table class="data">
                    <tbody>
                        <tr>
                            <td>Cost of goods sold</td>

                            <td class="num">
                                UGX {{ number_format($report['profit']['summary']['cost_of_goods']) }}
                            </td>
                        </tr>

                        <tr>
                            <td>Gross profit</td>

                            <td class="num">
                                UGX {{ number_format($report['profit']['summary']['gross_profit']) }}
                            </td>
                        </tr>

                        <tr>
                            <td>Gross margin</td>

                            <td class="num">
                                {{ $report['profit']['summary']['margin'] === null
                                    ? '—'
                                    : $report['profit']['summary']['margin'].'%'
                                }}
                            </td>
                        </tr>

                        <tr class="strong-row">
                            <td>Profit after expenses</td>

                            <td class="num">
                                UGX {{ number_format($report['profit']['summary']['operating_profit']) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endif


    {{-- ================================================================
        FOOTER
    ================================================================= --}}

    <div class="footer">
        <table class="footer-table">
            <tr>
                <td class="footer-left">
                    <span class="footer-brand">
                        Generated by NileBit POS for Retail
                    </span>

                    · {{ $shop->name }}

                    · {{ $generatedAt->format('j M Y, H:i T') }}
                </td>

                <td class="footer-right"></td>
            </tr>
        </table>
    </div>


    {{--
        DomPDF evaluates this during rendering, when it knows the real total
        number of pages. This avoids the old "Page 1 of 0" result.
    --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->get_font("DejaVu Sans", "normal");
            $size = 7;

            $text = "Page {PAGE_NUM} of {PAGE_COUNT}";

            $width = $fontMetrics->get_text_width(
                "Page 99 of 99",
                $font,
                $size
            );

            $x = $pdf->get_width() - 40 - $width;
            $y = $pdf->get_height() - 34;

            $pdf->page_text(
                $x,
                $y,
                $text,
                $font,
                $size,
                array(0.44, 0.50, 0.48)
            );
        }
    </script>

</body>
</html>