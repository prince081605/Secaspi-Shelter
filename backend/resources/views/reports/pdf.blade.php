<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: sans-serif; color: #14181a; font-size: 12px; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .meta { color: #5b6472; font-size: 11px; margin-bottom: 18px; }
        .summary { display: table; width: 100%; margin-bottom: 20px; }
        .chip { display: inline-block; border: 1px solid #e6e8e6; border-radius: 6px; padding: 6px 10px; margin: 0 6px 6px 0; }
        .chip-label { color: #5b6472; font-size: 10px; text-transform: uppercase; }
        .chip-value { font-weight: bold; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #e6e8e6; padding: 6px 8px; text-align: left; font-size: 11px; }
        th { background: #f7f8f7; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">Generated {{ $generatedAt }} &middot; SECASPI Shelter</div>

    <div class="summary">
        @foreach ($summary as $stat)
            <div class="chip">
                <div class="chip-label">{{ $stat['label'] }}</div>
                <div class="chip-value">{{ $stat['value'] }}</div>
            </div>
        @endforeach
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($columns as $col)
                    <th>{{ $col['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($columns as $col)
                        <td>{{ $row[$col['key']] ?? '—' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}">No data for this filter.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
