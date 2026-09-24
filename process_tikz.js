/**
 * TỆP HỖ TRỢ RENDER TIKZJAX CHO GIAO DIỆN REACT
 * Tên file: process_tikz.js
 * Chức năng: Đánh thức thư viện TikZJax quét lại trang để vẽ hình sau khi câu hỏi hiển thị
 */
window.processTikz = function() {
    // Nếu đang có một lệnh đếm ngược vẽ hình nào đó, thì hủy nó đi để tránh vẽ trùng lặp
    if (window.tikzTimeout) {
        clearTimeout(window.tikzTimeout);
    }
    
    // Đợi 100ms (mili-giây) để câu hỏi tải hoàn tất lên màn hình
    window.tikzTimeout = setTimeout(function() {
        // Phát ra tín hiệu "Trang đã tải xong" để gọi TikZJax đi tìm và vẽ các hình
        document.dispatchEvent(new Event('DOMContentLoaded'));
    }, 100);
};