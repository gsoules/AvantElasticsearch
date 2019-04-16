<script type="text/javascript">
jQuery(document).ready(function()
{
    jQuery( function() {
        jQuery( "#query" ).autocomplete(
        {
            source: '<?php echo url('/elasticsearch/suggest'); ?>',
            select: function (e, ui)
            {
                var query = ui.item.value;
                window.location.href = ui.item.value;
                return false;
            },
            focus: function (event, ui)
            {
                // Prevent the text of the item being hovered over from appearing in the search box.
                return false;
            }
        });
    });
});
</script>