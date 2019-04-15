<script type="text/javascript">
jQuery(document).ready(function()
{
    jQuery( function() {
        jQuery( "#query" ).autocomplete({
            source: '<?php echo url('/elasticsearch/suggest'); ?>',
        });
    } );
});
</script>