<ul>
    <{foreach item=module from=$block.modules}>
        <li><a href="<{$module.adminlink}>" title="<{$module.lang_linkname}>"><{$module.lang_linkname}></a>: <{$module.pendingnum}></li>
    <{/foreach}>
</ul>
