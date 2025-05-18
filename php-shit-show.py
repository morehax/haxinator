#!/usr/bin/env python3
# PHP Dangerous Functions Scanner
# Author: MoreHax (haxinatorgalore@gmail.com)
# This script analyzes PHP files for potentially dangerous function calls, generating a detailed
# HTML report with occurrence details, function counts, and file summaries. Designed for developers
# and contributors to identify and review security-sensitive code in open-source projects.import os
import re
import os
import sys
from collections import defaultdict
import html
from datetime import datetime

def get_cell_color(count):
    if count == 0:
        return ""  # No color for 0
    if count <= 4:
        return "background: #ffcccc;"  # Light red
    elif count <= 8:
        return "background: #ff9999;"  # Medium-light red
    elif count <= 12:
        return "background: #ff6666;"  # Medium red
    elif count <= 16:
        return "background: #ff3333;"  # Medium-dark red
    else:
        return "background: #ff0000;"  # Dark red

def scan_directory(directory):
    # Comprehensive list of dangerous PHP functions
    dangerous_functions = [
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec',
        'eval', 'assert', 'create_function',
        'file_get_contents', 'file_put_contents', 'fopen', 'readfile', 'unlink', 'rmdir',
        'move_uploaded_file', 'copy', 'rename', 'mkdir', 'chdir', 'chroot',
        'include', 'include_once', 'require', 'require_once',
        'phpinfo', 'getenv', 'ini_get', 'ini_set',
        'unserialize', 'serialize',
        'curl_exec', 'curl_multi_exec', 'fsockopen', 'socket_create',
        'preg_replace', 'ob_start', 'register_shutdown_function', 'register_tick_function'
    ]
    pattern = re.compile(r'\b(' + '|'.join(dangerous_functions) + r')\s*\(', re.IGNORECASE)
    
    # Store data
    occurrences = []
    function_counts = {func.lower(): 0 for func in dangerous_functions}
    file_incidents = defaultdict(int)
    function_file_counts = defaultdict(lambda: defaultdict(int))
    scanned_files = []
    total_count = 0
    
    # Validate directory
    if not os.path.isdir(directory):
        print(f"Error: {directory} is not a directory or does not exist.")
        sys.exit(1)
    
    # Traverse directory
    for root, dirs, files in os.walk(directory):
        for file in files:
            if file.endswith('.php'):
                file_path = os.path.join(root, file)
                scanned_files.append(file_path)
                try:
                    with open(file_path, 'r', encoding='utf-8') as f:
                        lines = f.readlines()
                        for i, line in enumerate(lines):
                            matches = pattern.findall(line)
                            for match in matches:
                                function_name = match.lower()
                                function_counts[function_name] += 1
                                file_incidents[file_path] += 1
                                function_file_counts[function_name][file_path] += 1
                                total_count += 1
                                context = lines[max(0, i-5):i+6]
                                occurrences.append({
                                    'file': file_path,
                                    'line': i + 1,
                                    'function': match,
                                    'context': context
                                })
                except Exception as e:
                    print(f"Warning: Could not read {file_path}: {e}")
    
    # Print console output
    print(f"Total dangerous function calls: {total_count}")
    print("Breakdown by function:")
    for func, count in sorted(function_counts.items()):
        if count > 0:
            print(f"- {func}: {count}")
    print("\nDetails of occurrences:")
    for occ in occurrences:
        print(f"File: {occ['file']}, Line: {occ['line']}, Function: {occ['function']}")
        print("Context:")
        for ctx_line in occ['context']:
            print(ctx_line.strip())
        print("...")
    
    print("\nSummary:")
    print(f"Total files scanned: {len(scanned_files)}")
    print(f"Total incidents: {total_count}")
    print("Files with incidents:")
    if file_incidents:
        for file, count in sorted(file_incidents.items()):
            print(f"- {file}: {count} incident(s)")
    else:
        print("- None")
    
    print("\nDangerous Functions by File (Table):")
    if total_count > 0:
        active_functions = sorted([func for func in dangerous_functions if function_counts[func.lower()] > 0], key=str.lower)
        files_with_incidents = sorted(file_incidents.keys())
        print("File".ljust(30) + "".join(f"{func[:10]:<12}" for func in active_functions))
        print("-" * (30 + 12 * len(active_functions)))
        for file in files_with_incidents:
            counts = [str(function_file_counts[func.lower()][file]) for func in active_functions]
            print(f"{file[:28]:<30}" + "".join(f"{count:<12}" for count in counts))
    else:
        print("No dangerous functions found.")
    
    # Generate HTML output
    # Build occurrence items
    occurrence_items = []
    for occ in occurrences:
        context_lines = "<br>".join(html.escape(line.rstrip()) for line in occ['context'])
        item = (
            '<div class="occurrence-item">\n'
            f'    <p><strong>File:</strong> {html.escape(occ["file"])}, <strong>Line:</strong> {occ["line"]}, <strong>Function:</strong> {html.escape(occ["function"])}</p>\n'
            f'    <div class="context-code">{context_lines}</div>\n'
            '</div>\n'
        )
        occurrence_items.append(item)
    
    # Build table headers and rows
    active_functions = sorted([func for func in dangerous_functions if function_counts[func.lower()] > 0], key=str.lower) if total_count > 0 else []
    table_headers = (
        "".join(f'<th scope="col" style="min-width: 80px; width: {max(70 / len(active_functions), 5)}%;">{html.escape(func[:10] + "..." if len(func) > 10 else func)}</th>' for func in active_functions)
        if active_functions else '<th>No functions found</th>'
    )
    
    table_rows = []
    if total_count > 0:
        files_with_incidents = sorted(file_incidents.keys())
        for file in files_with_incidents:
            counts = [function_file_counts[func.lower()][file] for func in active_functions]
            row_cells = "".join(f"<td style='{get_cell_color(count)}'>{count}</td>" for count in counts)
            row = f"<tr>\n    <td>{html.escape(file[:28])}</td>\n    {row_cells}\n</tr>\n"
            table_rows.append(row)
    else:
        table_rows.append('<tr><td colspan="1">No dangerous functions found</td></tr>\n')
    
    # Build HTML using triple quotes
    html_content = f"""<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Haxinator 2000 - PHP Security Scan</title>
    <link href="/css/bootstrap.min.css" rel="stylesheet" />
    <link href="/css/theme.css" rel="stylesheet" />
    <link rel="stylesheet" href="/css/bootstrap-icons/bootstrap-icons.min.css">
    <style>
        body {{
            background: linear-gradient(135deg, #65ddb7 0%, #3a7cbd 100%);
            font-family: "Segoe UI", Arial, sans-serif;
            min-height: 100vh;
            margin: 0;
        }}
        .container {{
            padding: 2rem 1rem;
        }}
        .config-card {{
            background: rgba(255, 255, 255, 0.98);
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            max-width: 1200px;
            margin: 0 auto;
        }}
        .config-section {{
            border-bottom: 1px solid #e0e7ef;
            padding: 1rem;
        }}
        .config-section:last-child {{
            border-bottom: none;
        }}
        .config-title {{
            font-size: 1.2rem;
            font-weight: 700;
            color: #1a365d;
            margin-bottom: 0.75rem;
            letter-spacing: 0.5px;
        }}
        .config-description {{
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.75rem;
        }}
        .table-responsive {{
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }}
        .nm-table {{
            margin: 0;
            width: 100%;
            border-collapse: collapse;
        }}
        .nm-table th, .nm-table td {{
            padding: 0.4rem 0.6rem;
            font-size: 0.85rem;
            text-align: left;
            border-right: 1px solid #e0e7ef;
        }}
        .nm-table th:last-child, .nm-table td:last-child {{
            border-right: none;
        }}
        .nm-table th {{
            position: sticky;
            top: 0;
            background: #e9f1fb;
            z-index: 1;
        }}
        .nm-table td:not(:first-child) {{
            text-align: center;
        }}
        .nm-table tbody tr:nth-child(even) {{
            background: #f8fafc;
        }}
        .nm-table tbody tr:hover {{
            background: #f1f5f9;
        }}
        .context-code {{
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 0.75rem;
            font-family: monospace;
            font-size: 0.8rem;
            white-space: pre-wrap;
            margin-top: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }}
        .occurrence-item {{
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e0e7ef;
        }}
        .occurrence-item:last-child {{
            border-bottom: none;
        }}
        .page-header {{
            text-align: center;
            color: #ffffff;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }}
        .report-date {{
            text-align: center;
            color: #ffffff;
            font-size: 1rem;
            margin-bottom: 1.5rem;
            opacity: 0.8;
        }}
        .footer {{
            text-align: center;
            color: #ffffff;
            font-size: 0.8rem;
            margin-top: 2rem;
            opacity: 0.8;
        }}
    </style>
</head>
<body>
    <div class="container">
        <h2 class="page-header">Haxinator 2000 - PHP Security Scan</h2>
        <div class="report-date">Generated on {datetime.now().strftime("%A, %B %d, %Y at %I:%M %p %Z")}</div>

        <div class="config-card">
            <!-- Statistics Section -->
            <div class="config-section">
                <div class="config-title">Scan Statistics</div>
                <div class="config-description">Overview of dangerous PHP function calls detected.</div>
                <p><strong>Total dangerous function calls:</strong> {total_count}</p>
                <p><strong>Breakdown by function:</strong></p>
                <ul>
                    {''.join(f"<li>{func}: {count}</li>" for func, count in sorted(function_counts.items()) if count > 0)}
                </ul>
            </div>

            <!-- Details Section -->
            <div class="config-section">
                <div class="config-title">Details of Occurrences</div>
                <div class="config-description">Specific instances of dangerous function calls with context.</div>
                {''.join(occurrence_items)}
            </div>

            <!-- Summary Section -->
            <div class="config-section">
                <div class="config-title">Summary</div>
                <div class="config-description">Summary of scanned files and incidents.</div>
                <p><strong>Total files scanned:</strong> {len(scanned_files)}</p>
                <p><strong>Total incidents:</strong> {total_count}</p>
                <p><strong>Files with incidents:</strong></p>
                <ul>
                    {''.join(f"<li>{html.escape(file)}: {count} incident(s)</li>" for file, count in sorted(file_incidents.items())) if file_incidents else "<li>None</li>"}
                </ul>
            </div>

            <!-- Table Section -->
            <div class="config-section">
                <div class="config-title">Dangerous Functions by File</div>
                <div class="config-description">Table showing incidents per function across files.</div>
                <div class="table-responsive">
                <table class="nm-table table">
                    <thead>
                        <tr>
                            <th scope="col" style="min-width: 200px; width: 30%;">File</th>
                            {table_headers}
                        </tr>
                    </thead>
                    <tbody>
                        {''.join(table_rows)}
                    </tbody>
                </table>
            </div>
        </div>
        <div class="footer">Generated on {datetime.now().strftime("%Y-%m-%d %H:%M:%S")}</div>
    </div>

    <script src="/js/bootstrap.bundle.min.js"></script>
</body>
</html>
"""
    
    # Write HTML to file
    try:
        with open("docs/php_scan_results.html", "w", encoding="utf-8") as f:
            f.write(html_content)
        print("\nHTML report generated: docs/php_scan_results.html")
    except Exception as e:
        print(f"Error writing HTML file: {e}")

if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Usage: python scan_php.py /path/to/directory")
        sys.exit(1)
    directory = sys.argv[1]
    scan_directory(directory)
