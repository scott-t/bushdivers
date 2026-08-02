import { Box, Text, useColorModeValue } from '@chakra-ui/react'
import React from 'react'

const ChatBubble = ({ children, isUser = false }) => {
  const bg = useColorModeValue(
    isUser ? 'gray.100' : 'orange.100',
    isUser ? 'gray.600' : 'orange.600'
  )
  return (
    <Box
      width="fit-content"
      maxWidth="65%"
      p={3}
      bgColor={bg}
      borderTopLeftRadius={isUser ? 8 : 0}
      borderTopRightRadius={isUser ? 0 : 8}
      borderBottomRightRadius={8}
      borderBottomLeftRadius={8}
    >
      <Text fontSize="sm" whiteSpace="pre-wrap">
        {children}
      </Text>
    </Box>
  )
}

export default ChatBubble
