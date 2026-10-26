<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weighbridge Slip - {{ $movement->movement_ref }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <!-- html2pdf.js for direct mobile A4 PDF downloads without physical printer -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #525659;
            color: #111;
            padding: 70px 10px 40px 10px;
            font-size: 13px;
        }

        /* Top Action Toolbar */
        .toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #1e293b;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            z-index: 9999;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            flex-wrap: wrap;
            gap: 10px;
        }

        .toolbar-title {
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge-ref {
            background: #f59e0b;
            color: #000;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 800;
            font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace;
        }

        .toolbar-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-download {
            background: #10b981;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
        }

        .btn-download:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        .btn-share {
            background: #25d366;
            color: #ffffff;
        }

        .btn-share:hover {
            background: #1ebe5d;
            transform: translateY(-1px);
        }

        .btn-primary {
            background: #ffd84d;
            color: #0b0b0b;
        }

        .btn-primary:hover {
            background: #f5c518;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        /* Mobile Notification Tip */
        .mobile-tip-banner {
            max-width: 800px;
            margin: 0 auto 16px auto;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* A4 Paper Container */
        .a4-page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 30px auto;
            background: #ffffff;
            box-shadow: 0 0 24px rgba(0, 0, 0, 0.35);
            padding: 16mm 18mm;
            position: relative;
        }

        .slip-border {
            border: 2px solid #0f172a;
            border-radius: 4px;
            padding: 14px;
            height: 100%;
        }

        @media (max-width: 820px) {
            body {
                padding: 110px 6px 30px 6px;
            }
            .toolbar {
                padding: 8px 12px;
            }
            .toolbar-actions {
                width: 100%;
            }
            .btn {
                flex: 1 1 auto;
                font-size: 12px;
                padding: 8px 10px;
                justify-content: center;
            }
            .a4-page {
                width: 100%;
                max-width: 100%;
                padding: 12px 10px;
                box-shadow: none;
                border-radius: 8px;
            }
        /* Slip Header */
        .header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 14px;
            margin-bottom: 16px;
        }

        .company-name {
            font-size: 22px;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: -0.5px;
            text-transform: uppercase;
        }

        .company-subtitle {
            font-size: 12px;
            color: #475569;
            margin-top: 3px;
            font-weight: 500;
        }

        .slip-title-box {
            text-align: right;
        }

        .slip-badge {
            display: inline-block;
            background: #0f172a;
            color: #ffd84d;
            padding: 4px 12px;
            font-weight: 800;
            font-size: 13px;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            border-radius: 4px;
        }

        .slip-number {
            font-family: 'JetBrains Mono', monospace;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 6px;
        }

        /* Grid Data Table */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 18px;
        }

        .info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 14px;
        }

        .info-box-title {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #64748b;
            margin-bottom: 8px;
            border-bottom: 1px dashed #cbd5e1;
            padding-bottom: 4px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 12px;
        }

        .info-row:last-child {
            margin-bottom: 0;
        }

        .info-label {
            color: #64748b;
            font-weight: 500;
        }

        .info-value {
            color: #0f172a;
            font-weight: 700;
            text-align: right;
        }

        .info-value.plate {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            background: #ffd84d;
            padding: 2px 8px;
            border-radius: 4px;
        }

        /* Weights Measurement Table */
        .weights-section {
            border: 2px solid #0f172a;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .weights-header {
            background: #0f172a;
            color: #fff;
            padding: 8px 14px;
            font-weight: 800;
            font-size: 12px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .weights-table {
            width: 100%;
            border-collapse: collapse;
        }

        .weights-table th {
            background: #f1f5f9;
            color: #334155;
            text-align: left;
            padding: 10px 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            border-bottom: 1px solid #cbd5e1;
        }

        .weights-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }

        .weight-num {
            font-family: 'JetBrains Mono', monospace;
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            text-align: right;
        }

        .net-weight-row {
            background: #fffbeb;
        }

        .net-weight-row td {
            border-top: 2px solid #0f172a;
            border-bottom: none;
            padding: 14px 12px;
        }

        .net-weight-label {
            font-size: 14px;
            font-weight: 900;
            color: #b45309;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .net-weight-val {
            font-family: 'JetBrains Mono', monospace;
            font-size: 22px;
            font-weight: 900;
            color: #15803d;
            text-align: right;
        }

        /* Quality & Verification Strip */
        .quality-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 24px;
        }

        .quality-label {
            font-weight: 700;
            font-size: 12px;
            color: #334155;
        }

        .quality-badge {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
            padding: 4px 10px;
            border-radius: 999px;
            font-weight: 800;
            font-size: 11px;
            text-transform: uppercase;
        }

        /* Signatures Section */
        .signatures-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-top: 35px;
            padding-top: 15px;
        }

        .sig-box {
            text-align: center;
            border-top: 1px solid #0f172a;
            padding-top: 8px;
        }

        .sig-title {
            font-size: 11px;
            font-weight: 700;
            color: #0f172a;
            text-transform: uppercase;
        }

        .sig-name {
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
        }

        /* Footer Notes */
        .slip-footer {
            margin-top: 30px;
            padding-top: 12px;
            border-top: 1px dashed #cbd5e1;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #64748b;
        }

        /* Exact A4 Print Optimization */
        @media print {
            body {
                background: #fff;
                padding: 0;
            }

            .toolbar {
                display: none !important;
            }

            .a4-page {
                width: 100% !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
            }

            .slip-border {
                border-width: 1.5px;
                padding: 12px;
            }

            @page {
                size: 210mm 297mm;
                margin: 8mm;
            }
        }
    </style>
</head>
<body>

    <!-- Top Action Toolbar -->
    <div class="toolbar">
        <div class="toolbar-title">
            <span>Vehicle Weighbridge Slip</span>
            <span class="badge-ref">{{ $movement->movement_ref }}</span>
        </div>
        <div class="toolbar-actions">
            <button id="btn-download-pdf" onclick="downloadA4PDF()" class="btn btn-download">
                📥 Download A4 PDF
            </button>
            <button onclick="shareViaMobile()" class="btn btn-share">
                📲 Share WhatsApp
            </button>
            <button onclick="window.print()" class="btn btn-primary">
                🖨️ Print / Save PDF
            </button>
            <button onclick="window.close()" class="btn btn-secondary">
                ✕ Close
            </button>
        </div>
    </div>

    <!-- Mobile Helpful Tip Banner -->
    <div class="mobile-tip-banner">
        <span style="font-size: 16px;">📱</span>
        <span>
            <strong>Mobile Tip:</strong> No printer needed! Tap <strong>"Download A4 PDF"</strong> to save the exact A4 file to your phone's downloads, or tap <strong>"Share WhatsApp"</strong> to send it to the driver.
        </span>
    </div>

    <!-- Official A4 Paper Container -->
    <div class="a4-page">
        <div class="slip-border">
            
            <!-- Header -->
            <div class="header">
                <div>
                    <h1 class="company-name">{{ $movement->company->name ?? 'INDUSTRIAL WEIGHBRIDGE FACILITY' }}</h1>
                    <p class="company-subtitle">
                        Department: {{ $movement->department->name ?? 'Logistics & Dispatch Division' }} · Electronic Computerized Scale
                    </p>
                </div>
                <div class="slip-title-box">
                    <span class="slip-badge">WEIGHMENT SLIP</span>
                    <div class="slip-number">REF #{{ $movement->movement_ref }}</div>
                </div>
            </div>

            <!-- Info Grid -->
            <div class="info-grid">
                <!-- Vehicle & Driver Details -->
                <div class="info-box">
                    <div class="info-box-title">Vehicle & Driver Information</div>
                    <div class="info-row">
                        <span class="info-label">Vehicle Plate:</span>
                        <span class="info-value plate">{{ $movement->vehicle_number }}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Driver Name:</span>
                        <span class="info-value">{{ $movement->driver_name ?? '—' }}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Driver Mobile:</span>
                        <span class="info-value">{{ $movement->driver_mobile ?? '—' }}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Driver License:</span>
                        <span class="info-value">{{ $movement->driver_license_no ?? '—' }}</span>
                    </div>
                </div>

                <!-- Consignment Details -->
                <div class="info-box">
                    <div class="info-box-title">Consignment & Trip Details</div>
                    <div class="info-row">
                        <span class="info-label">Material:</span>
                        <span class="info-value">{{ $movement->material->name ?? 'Standard Freight Cargo' }}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Purpose:</span>
                        <span class="info-value" style="text-transform: capitalize;">{{ $movement->purpose }}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Supplier/Party:</span>
                        <span class="info-value">{{ $movement->supplier_or_customer ?? '—' }}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Weighing Mode:</span>
                        <span class="info-value">
                            {{ $movement->weighing_mode === 'loaded_first' ? 'Loaded First' : 'Empty First' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Gate Timestamps -->
            <div class="info-grid" style="margin-bottom: 14px;">
                <div class="info-box">
                    <div class="info-box-title">Gate Inward Check</div>
                    <div class="info-row">
                        <span class="info-label">Gate In Time:</span>
                        <span class="info-value">{{ $movement->entry_at ? $movement->entry_at->format('d M Y, h:i A') : '—' }}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Security Officer:</span>
                        <span class="info-value">{{ $movement->enteredBy->name ?? 'Security Gate 1' }}</span>
                    </div>
                </div>

                <div class="info-box">
                    <div class="info-box-title">Gate Outward Check</div>
                    <div class="info-row">
                        <span class="info-label">Gate Out Time:</span>
                        <span class="info-value">{{ $movement->exit_at ? $movement->exit_at->format('d M Y, h:i A') : 'Still Inside' }}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Exit Clearance By:</span>
                        <span class="info-value">{{ $movement->exitedBy->name ?? 'Pending Exit' }}</span>
                    </div>
                </div>
            </div>

            <!-- Weights Section -->
            @php
                $rawUnit = strtolower($movement->weight_unit ?? 'kg');
                $isSlipMt = in_array($rawUnit, ['tonne', 'tonnes', 'mt', 'metric_ton', 'metric ton']);
                $slipUnitDisplay = $isSlipMt ? 'MT' : strtoupper($movement->weight_unit ?? 'KG');
                $slipDecimals = $isSlipMt ? 3 : 2;
            @endphp
            <div class="weights-section">
                <div class="weights-header">
                    <span>Electronic Weight Measurement Log</span>
                    <span>Scale Unit: {{ $slipUnitDisplay }}</span>
                </div>
                <table class="weights-table">
                    <thead>
                        <tr>
                            <th style="width: 15%;">Seq</th>
                            <th style="width: 25%;">Weighment Type</th>
                            <th style="width: 35%;">Date & Time</th>
                            <th style="width: 25%; text-align: right;">Measured Weight</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($movement->weighments as $w)
                            @php
                                $wRaw = strtolower($w->unit ?? $movement->weight_unit ?? 'kg');
                                $isWMt = in_array($wRaw, ['tonne', 'tonnes', 'mt', 'metric_ton', 'metric ton']);
                                $wUnitDisp = $isWMt ? 'MT' : ($w->unit ?? 'kg');
                                $wDec = $isWMt ? 3 : 2;
                            @endphp
                            <tr>
                                <td><strong>#{{ $w->sequence }}</strong></td>
                                <td style="text-transform: capitalize; font-weight: 700;">{{ $w->weight_type }} Weight</td>
                                <td style="color: #64748b;">{{ $w->weighed_at ? \Carbon\Carbon::parse($w->weighed_at)->format('d M Y, h:i:s A') : '—' }}</td>
                                <td class="weight-num">{{ number_format($w->weight, $wDec) }} {{ $wUnitDisp }}</td>
                            </tr>
                        @empty
                            @php
                                $singleWeight = $movement->loaded_weight ?? $movement->empty_weight ?? 0;
                            @endphp
                            <tr>
                                <td>#1</td>
                                <td>{{ $movement->weighing_mode === 'loaded_first' ? 'Loaded Weight' : 'Empty (Tare) Weight' }}</td>
                                <td>{{ $movement->entry_at ? $movement->entry_at->format('d M Y, h:i A') : '—' }}</td>
                                <td class="weight-num">
                                    {{ number_format($singleWeight, $slipDecimals) }} {{ $slipUnitDisplay }}
                                </td>
                            </tr>
                        @endforelse

                        <!-- Net Weight Row -->
                        <tr class="net-weight-row">
                            <td colspan="2">
                                <div class="net-weight-label">NET MATERIAL WEIGHT</div>
                                <div style="font-size: 11px; color: #64748b; margin-top: 2px;">Net = Loaded Weight − Empty (Tare) Weight</div>
                            </td>
                            <td colspan="2" class="net-weight-val">
                                {{ $movement->net_weight !== null ? number_format($movement->net_weight, $slipDecimals) : number_format(0, $slipDecimals) }} {{ $slipUnitDisplay }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Quality Inspection Strip -->
            <div class="quality-strip">
                <div>
                    <span class="quality-label">Quality Inspection Result: </span>
                    <span style="font-weight: 800; color: #0f172a; text-transform: uppercase;">
                        {{ $movement->quality_result ?? 'Approved / Passed' }}
                    </span>
                </div>
                <div class="quality-badge">
                    ✓ Verified Quality Lab
                </div>
            </div>

            <!-- Signatures Grid -->
            <div class="signatures-grid">
                <div class="sig-box">
                    <div style="height: 38px;"></div>
                    <div class="sig-title">Driver's Signature</div>
                    <div class="sig-name">{{ $movement->driver_name ?? 'Vehicle Operator' }}</div>
                </div>

                <div class="sig-box">
                    <div style="height: 38px;"></div>
                    <div class="sig-title">Weighbridge Operator</div>
                    <div class="sig-name">{{ $movement->verifiedBy->name ?? 'Authorized In-charge' }}</div>
                </div>

                <div class="sig-box">
                    <div style="height: 38px;"></div>
                    <div class="sig-title">Security Gate Stamp</div>
                    <div class="sig-name">Main Gate Post 1</div>
                </div>
            </div>

            <!-- Footer -->
            <div class="slip-footer">
                <span>Generated on: {{ now()->format('d M Y, h:i A') }} · WeighBridge Pro Management System</span>
                <span>Page 1 of 1 · Official Document</span>
            </div>

        </div>
    </div>

    <script>
        // Direct Client-Side A4 PDF Generator (Saves exact A4 file on mobile storage)
        function downloadA4PDF() {
            const btn = document.getElementById('btn-download-pdf');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '⏳ Generating PDF...';
            btn.disabled = true;

            const element = document.querySelector('.a4-page');

            // Exact ISO A4 configuration (210mm x 297mm)
            const opt = {
                margin: [4, 4, 4, 4],
                filename: 'Weighbridge_Slip_{{ $movement->movement_ref }}.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    scrollX: 0,
                    scrollY: 0,
                },
                jsPDF: {
                    unit: 'mm',
                    format: 'a4',
                    orientation: 'portrait'
                }
            };

            if (window.html2pdf) {
                html2pdf().set(opt).from(element).save().then(() => {
                    btn.innerHTML = '✓ Downloaded!';
                    setTimeout(() => {
                        btn.innerHTML = originalHtml;
                        btn.disabled = false;
                    }, 2000);
                }).catch((err) => {
                    console.error('PDF generation error:', err);
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                    window.print();
                });
            } else {
                window.print();
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        }

        // Native Mobile Share (WhatsApp, Email, Telegram, Files)
        async function shareViaMobile() {
            const title = 'Weighbridge Slip - {{ $movement->movement_ref }}';
            const text = 'Weighbridge Weighment Slip\nVehicle: {{ $movement->vehicle_number }}\nRef: {{ $movement->movement_ref }}\nNet Weight: {{ $movement->net_weight ?? 0 }} {{ $movement->weight_unit ?? "kg" }}\nStatus: {{ strtoupper($movement->status) }}';
            const url = window.location.href;

            if (navigator.share) {
                try {
                    await navigator.share({
                        title: title,
                        text: text,
                        url: url
                    });
                } catch (err) {
                    if (err.name !== 'AbortError') {
                        fallbackWhatsApp(text, url);
                    }
                }
            } else {
                fallbackWhatsApp(text, url);
            }
        }

        function fallbackWhatsApp(text, url) {
            const waUrl = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(text + '\n' + url);
            window.open(waUrl, '_blank');
        }
    </script>

    @if($autoPrint)
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                window.print();
            }, 600);
        });
    </script>
    @endif

</body>
</html>
