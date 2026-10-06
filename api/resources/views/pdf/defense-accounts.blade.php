<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>BizTrack — Accounts</title>
    <style>
        @page { margin: 40px 36px 48px; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1a1a1a; margin: 0; }
        h1 { font-size: 17px; color: #0025cc; margin: 0 0 4px; }
        .meta { font-size: 9px; color: #666; margin-bottom: 14px; }
        .box { border: 1px solid #c9d0f5; background: #f4f6ff; padding: 8px 10px; margin-bottom: 10px; font-size: 10px; }
        .box strong { font-size: 12px; }
        .offices td { font-size: 9.5px; padding: 2px 10px 2px 0; border: none; }
        h2 { font-size: 11.5px; color: #0025cc; margin: 16px 0 4px; page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; }
        table.accounts { page-break-inside: avoid; table-layout: fixed; }
        th { background: #f0f2ff; text-align: left; padding: 4px 5px; font-size: 8.5px; border-bottom: 2px solid #0025cc; }
        td { padding: 4px 5px; font-size: 8.5px; border-bottom: 1px solid #e6e6e6; vertical-align: top; }
        td.email { word-break: break-all; }
        .empty { font-size: 9px; color: #666; font-style: italic; }
    </style>
</head>
<body>
    <h1>BizTrack — Accounts</h1>
    <div class="meta">Generated {{ $generated }}</div>

    <div class="box">
        Password for every owner account below: <strong>{{ $password }}</strong>
    </div>

    <div class="box">
        Office accounts that did the office work (their passwords are not printed here):
        <table class="offices">
            @foreach ($offices as $office)
                <tr><td>{{ $office['office'] }}</td><td>{{ $office['email'] }}</td></tr>
            @endforeach
        </table>
    </div>

    @foreach ($sections as $section)
        <h2>{{ $loop->iteration }}. {{ $section['title'] }}</h2>
        @if ($section['rows'] === [])
            <div class="empty">No account in this scenario.</div>
        @else
            <table class="accounts">
                <thead>
                    <tr>
                        <th style="width: 3%">#</th>
                        <th style="width: 15%">Owner</th>
                        <th style="width: 32%">Sign-in e-mail</th>
                        <th style="width: 20%">Business</th>
                        <th style="width: 15%">Tracking ID / permit no.</th>
                        <th style="width: 15%">State</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['rows'] as $row)
                        <tr>
                            <td>{{ $row['n'] }}</td>
                            <td>{{ $row['name'] }}</td>
                            <td class="email">{{ $row['email'] }}</td>
                            <td>{{ $row['business'] }}</td>
                            <td>{{ $row['reference'] }}</td>
                            <td>{{ $row['state'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
