jQuery(document).ready(function ($) {
    
    if($('#edit-configuration-coinsnap-provider').length){
        
        setProvider();
        $('#edit-configuration-coinsnap-provider').change(function(){
            setProvider();
        });
    }
    
    if($('#edit-configuration-coinsnap-discount-enabled').length){
        
        enableDiscount();
        
        $('#edit-configuration-coinsnap-discount-enabled').change(function(){
            enableDiscount();
        });
        
        $('#edit-configuration-coinsnap-discount-amount-limit').change(function(){
            if(parseFloat($(this).val()) < 0){
                $(this).val(0);
            }
            if(parseFloat($(this).val()) > 100){
                $(this).val(100);
            }
        });
        
        $('#edit-configuration-coinsnap-discount-percentage').change(function(){
            if(parseFloat($(this).val()) < 0){
                $(this).val(0);
            }
            if(parseFloat($(this).val()) > 100){
                $(this).val(100);
            }
        });
        
        $('.discount input').keyup(function() {
            $(this).val($(this).val().replace(/[^0-9,]/g,''));
        });
        
        if($('#edit-configuration-coinsnap-discount-enabled').prop('checked')){
            setDiscount();
        }
        
        $('#edit-configuration-coinsnap-discount-type').change(function(){
            setDiscount();
        });
    }
    
    function setProvider(){
        if($('#edit-configuration-coinsnap-provider').val() !== 'btcpay'){
            $('.form-item--configuration-coinsnap-btcpay-server-url').hide();
            $('.form-item--configuration-coinsnap-btcpay-server-url input[type=text]').removeAttr('required');
            $('.form-item--configuration-coinsnap-btcpay-store-id').hide();
            $('.form-item--configuration-coinsnap-btcpay-store-id input[type=text]').removeAttr('required');
            $('.form-item--configuration-coinsnap-btcpay-api-key').hide();
            $('.form-item--configuration-coinsnap-btcpay-api-key input[type=text]').removeAttr('required');
            
            $('.form-item--configuration-coinsnap-store-id').show();
            $('.form-item--configuration-coinsnap-store-id input[type=text]').attr('required','required');
            $('.form-item--configuration-coinsnap-api-key').show();
            $('.form-item--configuration-coinsnap-api-key input[type=text]').attr('required','required');
        }
        else {
            $('.form-item--configuration-coinsnap-store-id').hide();
            $('.form-item--configuration-coinsnap-store-id input[type=text]').removeAttr('required');
            $('.form-item--configuration-coinsnap-api-key').hide();
            $('.form-item--configuration-coinsnap-api-key input[type=text]').removeAttr('required');
            
            $('.form-item--configuration-coinsnap-btcpay-server-url').show();
            $('.form-item--configuration-coinsnap-btcpay-server-url input[type=text]').attr('required','required');
            $('.form-item--configuration-coinsnap-btcpay-store-id').show();
            $('.form-item--configuration-coinsnap-btcpay-store-id input[type=text]').attr('required','required');
            $('.form-item--configuration-coinsnap-btcpay-api-key').show();
            $('.form-item--configuration-coinsnap-btcpay-api-key input[type=text]').attr('required','required');
        }
    }
    
    function enableDiscount(){
        if($('#edit-configuration-coinsnap-discount-enabled').prop('checked')){
            $('.form-item--configuration-coinsnap-discount-type').show();
            $('.form-item--configuration-coinsnap-discount-amount').show();
            $('.form-item--configuration-coinsnap-discount-amount-limit').show();
            $('.form-item--configuration-coinsnap-discount-percentage').show();
            setDiscount();
        }
        else {
            $('.form-item--configuration-coinsnap-discount-type').hide();
            $('.form-item--configuration-coinsnap-discount-amount').hide();
            $('.form-item--configuration-coinsnap-discount-amount-limit').hide();
            $('.form-item--configuration-coinsnap-discount-percentage').hide();
        }
    }
    
    function setDiscount(){
        if($('#edit-configuration-coinsnap-discount-type').val() === 'fixed'){
            $('.form-item--configuration-coinsnap-discount-percentage').hide();
            $('.form-item--configuration-coinsnap-discount-amount').show();
            $('.form-item--configuration-coinsnap-discount-amount-limit').show();
        }
        else {
            $('.form-item--configuration-coinsnap-discount-amount').hide();
            $('.form-item--configuration-coinsnap-discount-amount-limit').hide();
            $('.form-item--configuration-coinsnap-discount-percentage').show();
        }
    }
});

