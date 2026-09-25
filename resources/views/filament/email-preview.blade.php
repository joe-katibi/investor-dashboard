<div class="oms-stack">
    <div><span class="oms-value">Subject:</span> {{ $subject }}</div>
    <div class="oms-email">
        {{-- Output of TemplateRenderer: template text and values are escaped; only OMS-generated tables are raw HTML. --}}
        {!! $html !!}
    </div>
</div>
