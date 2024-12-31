<script defer>
    document.addEventListener("DOMContentLoaded", function() {
        let mc = document.getElementById('modelCount');
        const p = performance.getEntriesByType("navigation")?.[0];
        const o = Object.fromEntries(p?.serverTiming?.map?.(({name, duration, description}) => ([name, duration, description])) ?? []);
        const r = parseInt(o.PageRender);
        const l = document.getElementById('stats');
        l.textContent = mc?.innerText + ' models ' + (o.cache ? '[' + o.cache + '] ' : '') + r + 'ms + ' + Math.ceil(p.responseStart - p.requestStart - r) + 'ms';
    });
</script>
